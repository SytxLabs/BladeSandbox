<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compiler;

use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Runtime\SandboxRuntime;

/**
 * Independently of how the file was generated, it may only contain:
 *  - inline HTML, echo, control structures (if/foreach/for/while/switch/break/continue),
 *  - literals, arrays, operators that cannot trigger implicit object behaviour,
 *  - plain local variables,
 *  - method calls on `$__sandbox` whose name is part of the runtime API,
 *  - `get_defined_vars()` and `extract($__sandbox->props|aware(...))` emitted by the scaffolding.
 *
 * Anything else (function calls, static calls, new, include, property access, offsets, ...) is rejected.
 */
final class PhpAstValidator
{
    private const STATEMENTS = [
        Stmt\InlineHTML::class, Stmt\Echo_::class, Stmt\Expression::class, Stmt\If_::class, Stmt\ElseIf_::class,
        Stmt\Else_::class, Stmt\Foreach_::class, Stmt\For_::class, Stmt\While_::class, Stmt\Switch_::class,
        Stmt\Case_::class, Stmt\Break_::class, Stmt\Continue_::class, Stmt\Nop::class,
    ];

    private const EXPRESSIONS = [
        Expr\Variable::class, Expr\MethodCall::class, Expr\FuncCall::class, Expr\Assign::class,
        Expr\PreInc::class, Expr\PreDec::class, Expr\PostInc::class, Expr\PostDec::class,
        Expr\BooleanNot::class, Expr\BitwiseNot::class, Expr\UnaryMinus::class, Expr\UnaryPlus::class,
        Expr\Ternary::class, Expr\Isset_::class, Expr\Empty_::class, Expr\Instanceof_::class, Expr\ConstFetch::class,
        Expr\Array_::class, Expr\List_::class, Expr\Match_::class,
        Expr\Cast\Int_::class, Expr\Cast\Double::class, Expr\Cast\Bool_::class,
        Node\Scalar\String_::class, Node\Scalar\Int_::class, Node\Scalar\Float_::class,
        Node\ArrayItem::class, Node\Arg::class, Node\MatchArm::class, Node\Identifier::class, Node\Name::class,
        Node\Name\FullyQualified::class,
    ];

    /** Operators that never invoke user-land object behaviour (loose comparisons are rewritten to compare()). */
    private const BINARY_OPERATORS = [
        BinaryOp\Plus::class, BinaryOp\Minus::class, BinaryOp\Mul::class, BinaryOp\Div::class, BinaryOp\Mod::class,
        BinaryOp\Pow::class, BinaryOp\BitwiseAnd::class, BinaryOp\BitwiseOr::class, BinaryOp\BitwiseXor::class,
        BinaryOp\ShiftLeft::class, BinaryOp\ShiftRight::class, BinaryOp\BooleanAnd::class, BinaryOp\BooleanOr::class,
        BinaryOp\LogicalAnd::class, BinaryOp\LogicalOr::class, BinaryOp\LogicalXor::class, BinaryOp\Identical::class,
        BinaryOp\NotIdentical::class, BinaryOp\Coalesce::class, BinaryOp\Concat::class,
    ];

    private const ASSIGN_OPERATORS = [
        Expr\AssignOp\Plus::class, Expr\AssignOp\Minus::class, Expr\AssignOp\Mul::class, Expr\AssignOp\Div::class,
        Expr\AssignOp\Mod::class, Expr\AssignOp\Pow::class, Expr\AssignOp\Concat::class, Expr\AssignOp\Coalesce::class,
    ];

    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
    }

    public function validate(string $compiled): void
    {
        try {
            $statements = $this->parser->parse($compiled) ?? [];
        } catch (ParserError $error) {
            throw InvalidSandboxTemplateException::syntax('unbalanced or malformed Blade structure ('. (preg_replace('/ on line \d+$/', '', $error->getRawMessage()) ?? 'syntax error') .')');
        }

        $visitor = new class ($this) extends NodeVisitorAbstract
        {
            public ?string $violation = null;

            public function __construct(private readonly PhpAstValidator $validator)
            {
            }

            public function enterNode(Node $node): ?int
            {
                $this->violation = $this->validator->check($node);

                return $this->violation !== null ? NodeVisitor::STOP_TRAVERSAL : null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($statements);
        $violation = $visitor->violation;

        if ($violation !== null) {
            throw InvalidSandboxTemplateException::forbiddenConstruct($violation);
        }
    }

    /** @internal Returns a violation description or null when the node is allowed. */
    public function check(Node $node): ?string
    {
        $class = $node::class;

        if ($node instanceof Stmt) {
            return in_array($class, self::STATEMENTS, true) ? $this->checkStatement($node) : 'statement '.$node->getType();
        }

        if ($node instanceof BinaryOp) {
            return in_array($class, self::BINARY_OPERATORS, true) ? null : 'operator '.$node->getType();
        }

        if ($node instanceof Expr\AssignOp) {
            return in_array($class, self::ASSIGN_OPERATORS, true) && $node->var instanceof Expr\Variable ? null : 'compound assignment';
        }

        if (!in_array($class, self::EXPRESSIONS, true)) {
            return 'node '.$node->getType();
        }

        return match (true) {
            $node instanceof Expr\Variable => is_string($node->name) && !in_array($node->name, SandboxRewriteVisitor::RESERVED_VARIABLES, true) ? null : 'variable',
            $node instanceof Expr\MethodCall => $this->checkMethodCall($node),
            $node instanceof Expr\FuncCall => $this->checkFunctionCall($node),
            $node instanceof Expr\Assign => $node->var instanceof Expr\Variable || $node->var instanceof Expr\List_ || $node->var instanceof Expr\Array_ ? null : 'assignment target',
            $node instanceof Expr\PreInc, $node instanceof Expr\PreDec, $node instanceof Expr\PostInc, $node instanceof Expr\PostDec => $node->var instanceof Expr\Variable ? null : 'increment target',
            $node instanceof Expr\Isset_ => $this->onlyVariables($node->vars) ? null : 'isset() on non-variables',
            $node instanceof Expr\Empty_ => $node->expr instanceof Expr\Variable ? null : 'empty() on non-variables',
            $node instanceof Expr\ConstFetch => in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true) ? null : 'constant',
            $node instanceof Node\Arg => $node->byRef || $node->unpack ? 'argument unpacking' : null,
            $node instanceof Node\ArrayItem => $node->byRef ? 'array reference' : null,
            default => null,
        };
    }

    private function checkStatement(Stmt $node): ?string
    {
        return match (true) {
            $node instanceof Stmt\Echo_ => $this->checkEcho($node),
            $node instanceof Stmt\Foreach_ => $node->byRef ? 'foreach by reference' : null,
            $node instanceof Stmt\Break_, $node instanceof Stmt\Continue_ => $node->num === null || $node->num instanceof Node\Scalar\Int_ ? null : 'dynamic break',
            default => null,
        };
    }

    /** Output only ever comes from the runtime (escaping) or from literal strings. */
    private function checkEcho(Stmt\Echo_ $node): ?string
    {
        foreach ($node->exprs as $expression) {
            if ($expression instanceof Node\Scalar\String_) {
                continue;
            }
            if ($expression instanceof Expr\MethodCall && $expression->var instanceof Expr\Variable && $expression->var->name === SandboxRewriteVisitor::RUNTIME_VARIABLE) {
                continue;
            }
            return 'echo of a value that was not escaped by the sandbox runtime';
        }
        return null;
    }

    private function checkMethodCall(Expr\MethodCall $node): ?string
    {
        if (!$node->var instanceof Expr\Variable || $node->var->name !== SandboxRewriteVisitor::RUNTIME_VARIABLE) {
            return 'method call outside the sandbox runtime';
        }
        if (!$node->name instanceof Node\Identifier || !in_array($node->name->toString(), SandboxRuntime::API, true)) {
            return 'unknown sandbox runtime method';
        }
        return null;
    }

    private function checkFunctionCall(Expr\FuncCall $node): ?string
    {
        if (!$node->name instanceof Node\Name) {
            return 'dynamic function call';
        }
        $name = strtolower(ltrim($node->name->toString(), '\\'));
        if ($name === 'get_defined_vars' && $node->args === []) {
            return null;
        }
        if ($name === 'extract' && count($node->args) === 1) {
            $argument = $node->args[0];
            if ($argument instanceof Node\Arg && $argument->value instanceof Expr\MethodCall && $argument->value->var instanceof Expr\Variable && $argument->value->var->name === SandboxRewriteVisitor::RUNTIME_VARIABLE && $argument->value->name instanceof Node\Identifier && in_array($argument->value->name->toString(), ['props', 'aware', 'loopVariables'], true)) {
                return null;
            }
        }
        return 'function call '.$name.'()';
    }

    /** @param array<Expr> $expressions */
    private function onlyVariables(array $expressions): bool
    {
        foreach ($expressions as $expression) {
            if (!$expression instanceof Expr\Variable) {
                return false;
            }
        }
        return true;
    }
}
