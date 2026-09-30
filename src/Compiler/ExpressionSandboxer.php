<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compiler;

use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpToken;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;

/**
 * Every PHP expression that originates from template source (echo contents, directive arguments, component attributes, loop heads) is parsed with nikic/php-parser,
 * validated against a closed set of constructs, and rewritten so that every operation that could reach application code goes through the sandbox runtime (`$__sandbox`).
 * The result is re-printed from the AST: raw template text is never copied into the compiled PHP.
 */
final class ExpressionSandboxer
{
    private Parser $parser;

    private PrettyPrinter $printer;

    private ?TemplateReferences $references = null;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->printer = new PrettyPrinter(['shortArraySyntax' => true]);
    }

    public function collectInto(?TemplateReferences $references): void
    {
        $this->references = $references;
    }

    public function expression(string $code, int $line): string
    {
        return $this->print($this->parseExpression($code, $line));
    }

    /** @return list<string> */
    public function arguments(string $code, int $line): array
    {
        if (trim($code) === '') {
            return [];
        }
        $call = $this->parseSingle('__sandbox_args__('.$code."\n);", $line);
        if (!$call instanceof Stmt\Expression || !$call->expr instanceof Expr\FuncCall || !$call->expr->name instanceof Node\Name || $call->expr->name->toString() !== '__sandbox_args__') {
            throw InvalidSandboxTemplateException::syntax('malformed directive arguments', $line);
        }
        $arguments = [];
        foreach ($call->expr->args as $argument) {
            if (!$argument instanceof Node\Arg || $argument->unpack || $argument->byRef || $argument->name !== null) {
                throw InvalidSandboxTemplateException::forbiddenConstruct('named, spread or by-reference directive arguments', $line);
            }
            $value = $this->rewrite($argument->value, $line);
            if (!$value instanceof Expr) {
                throw InvalidSandboxTemplateException::syntax('malformed directive arguments', $line);
            }
            $arguments[] = $this->print($value);
        }

        return $arguments;
    }

    /** @return list<string> */
    public function assignments(string $code, int $line): array
    {
        $statements = [];
        foreach (self::splitStatements($code, $line) as [$segment, $segmentLine]) {
            array_push($statements, ...$this->assignmentList($segment, $segmentLine));
        }
        if ($statements === []) {
            throw InvalidSandboxTemplateException::syntax('@var expects assignments like @var($name = $value)', $line);
        }
        return $statements;
    }

    /** @return list<string> */
    private function assignmentList(string $code, int $line): array
    {
        $call = $this->parseSingle('__sandbox_args__('.$code."\n);", $line);
        if (!$call instanceof Stmt\Expression || !$call->expr instanceof Expr\FuncCall || $call->expr->args === []) {
            throw InvalidSandboxTemplateException::syntax('@var expects assignments like @var($name = $value)', $line);
        }

        $statements = [];
        foreach ($call->expr->args as $argument) {
            if (!$argument instanceof Node\Arg || $argument->unpack || $argument->byRef || $argument->name !== null || !($argument->value instanceof Expr\Assign || $argument->value instanceof Expr\AssignOp || $argument->value instanceof Expr\PreInc || $argument->value instanceof Expr\PostInc || $argument->value instanceof Expr\PreDec || $argument->value instanceof Expr\PostDec)) {
                throw InvalidSandboxTemplateException::syntax('@var expects assignments like @var($name = $value)', $line);
            }
            $assignment = $this->rewrite($argument->value, $line);
            assert($assignment instanceof Expr);
            $statements[] = $this->print($assignment);
        }

        return $statements;
    }

    /** @return list<array{0: string, 1: int}> segment and its template line */
    private static function splitStatements(string $code, int $line): array
    {
        $segments = [];
        $current = '';
        $currentLine = $line;
        $lineNumber = $line;
        $depth = 0;

        foreach (PhpToken::tokenize('<?php '.$code) as $index => $token) {
            if ($index === 0 && $token->id === T_OPEN_TAG) {
                continue;
            }
            if ($token->id === T_CLOSE_TAG || $token->id === T_INLINE_HTML || $token->id === T_OPEN_TAG || $token->id === T_OPEN_TAG_WITH_ECHO) {
                throw InvalidSandboxTemplateException::syntax('PHP tags are not allowed in @var', $lineNumber);
            }

            $text = $token->text;
            if ($token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES || in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($depth === 0 && $text === ';') {
                if (trim(self::withoutComments($current)) !== '') {
                    $segments[] = [$current, $currentLine];
                }
                $current = '';
                $lineNumber += substr_count($text, "\n");
                $currentLine = $lineNumber;
                continue;
            }

            if ($current === '' && trim($text) === '') {
                $lineNumber += substr_count($text, "\n");
                $currentLine = $lineNumber;
                continue;
            }

            $current .= $text;
            $lineNumber += substr_count($text, "\n");
        }

        if (trim(self::withoutComments($current)) !== '') {
            $segments[] = [$current, $currentLine];
        }

        return $segments;
    }

    private static function withoutComments(string $code): string
    {
        $result = '';
        foreach (PhpToken::tokenize('<?php '.$code) as $index => $token) {
            if ($index === 0 || $token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
                continue;
            }
            $result .= $token->text;
        }

        return $result;
    }

    /** @return array{subject: string, key: ?string, value: string} */
    public function foreachHead(string $head, int $line): array
    {
        $loop = $this->parseSingle('foreach ('.$head."\n) {}", $line);
        if (!$loop instanceof Stmt\Foreach_ || $loop->stmts !== []) {
            throw InvalidSandboxTemplateException::syntax('malformed foreach', $line);
        }
        $loop = $this->rewrite($loop, $line);
        assert($loop instanceof Stmt\Foreach_);
        return ['subject' => $this->print($loop->expr), 'key' => $loop->keyVar !== null ? $this->print($loop->keyVar) : null, 'value' => $this->print($loop->valueVar)];
    }

    public function forHead(string $head, int $line): string
    {
        $loop = $this->parseSingle('for ('.$head."\n) {}", $line);
        if (!$loop instanceof Stmt\For_ || $loop->stmts !== []) {
            throw InvalidSandboxTemplateException::syntax('malformed for loop', $line);
        }
        $loop = $this->rewrite($loop, $line);
        assert($loop instanceof Stmt\For_);
        $print = fn (array $expressions): string => implode(', ', array_map(fn (Expr $expr): string => $this->print($expr), $expressions));
        return $print($loop->init).'; '.$print($loop->cond).'; '.$print($loop->loop);
    }

    private function parseExpression(string $code, int $line): Expr
    {
        if (trim($code) === '') {
            throw InvalidSandboxTemplateException::syntax('empty expression', $line);
        }
        $statement = $this->parseSingle('('.$code."\n);", $line);
        if (!$statement instanceof Stmt\Expression) {
            throw InvalidSandboxTemplateException::syntax('expected a single expression', $line);
        }
        $expression = $this->rewrite($statement->expr, $line);
        assert($expression instanceof Expr);
        return $expression;
    }

    private function parseSingle(string $code, int $line): Node
    {
        try {
            $statements = $this->parser->parse('<?php '.$code);
        } catch (ParserError) {
            throw InvalidSandboxTemplateException::syntax('PHP syntax error in expression', $line);
        }
        if ($statements === null || count($statements) !== 1) {
            throw InvalidSandboxTemplateException::syntax('expected a single expression', $line);
        }
        return $statements[0];
    }

    private function rewrite(Node $node, int $line): Node
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new SandboxRewriteVisitor($line, $this->references));
        return $traverser->traverse([$node])[0];
    }

    private function print(Expr $expression): string
    {
        return $this->printer->prettyPrintExpr($expression);
    }
}
