<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compiler;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;

final class SandboxRewriteVisitor extends NodeVisitorAbstract
{
    public const RUNTIME_VARIABLE = '__sandbox';
    public const RESERVED_VARIABLES = ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'http_response_header', 'php_errormsg', 'argv', 'argc'];
    private const COMPARISONS = [BinaryOp\Equal::class => '==', BinaryOp\NotEqual::class => '!=', BinaryOp\Smaller::class => '<', BinaryOp\SmallerOrEqual::class => '<=', BinaryOp\Greater::class => '>', BinaryOp\GreaterOrEqual::class => '>=', BinaryOp\Spaceship::class => '<=>'];

    public function __construct(private readonly int $line, private readonly ?TemplateReferences $references = null)
    {
    }

    public function enterNode(Node $node): null
    {
        $node->setAttribute('comments', []);

        $forbidden = $this->forbidden($node);
        if ($forbidden !== null) {
            throw InvalidSandboxTemplateException::forbiddenConstruct($forbidden, $this->line);
        }

        if ($node instanceof Expr\Isset_) {
            foreach ($node->vars as $var) {
                $this->markQuiet($var, true);
            }
        } elseif ($node instanceof Expr\Empty_) {
            $this->markQuiet($node->expr, true);
        } elseif ($node instanceof BinaryOp\Coalesce) {
            $this->markQuiet($node->left, true);
        } elseif ($node instanceof Expr\Assign) {
            $this->assertAssignable($node->var, true);
        } elseif ($node instanceof Expr\AssignOp || $node instanceof Expr\PreInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostInc || $node instanceof Expr\PostDec) {
            $this->assertAssignable($node->var, false);
        } elseif ($node instanceof Stmt\Foreach_) {
            if ($node->byRef) {
                throw InvalidSandboxTemplateException::forbiddenConstruct('foreach by reference', $this->line);
            }
            if ($node->keyVar !== null) {
                $this->assertAssignable($node->keyVar, false);
            }
            $this->assertAssignable($node->valueVar, true);
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        $this->collect($node);

        return match (true) {
            $node instanceof Expr\FuncCall => $this->runtime('fn', [new Scalar\String_(ltrim($this->nameOf($node->name), '\\')), $this->argumentArray($node->args)], $node),
            $node instanceof Expr\NullsafeMethodCall, $node instanceof Expr\MethodCall => $this->methodCall($node),
            $node instanceof Expr\StaticCall => $this->staticCall($node),
            $node instanceof Expr\NullsafePropertyFetch, $node instanceof Expr\PropertyFetch => $this->propertyFetch($node),
            $node instanceof Expr\ArrayDimFetch => $this->offsetFetch($node),
            $node instanceof Expr\Variable && $node->getAttribute('sb_quiet_base') === true => new BinaryOp\Coalesce($node, $this->constant('null')),
            $node instanceof Expr\ClassConstFetch => $this->classConstant($node),
            $node instanceof Expr\ConstFetch => $this->constantFetch($node),
            $node instanceof Scalar\InterpolatedString => $this->interpolated($node),
            $node instanceof BinaryOp\Concat => new BinaryOp\Concat($this->stringify($node->left), $this->stringify($node->right)),
            $node instanceof Expr\AssignOp\Concat => new Expr\AssignOp\Concat($node->var, $this->stringify($node->expr)),
            $node instanceof Cast\String_ => $this->stringify($node->expr, true),
            $node instanceof Cast\Array_ => $this->runtime('toArray', [$node->expr]),
            $node instanceof BinaryOp && isset(self::COMPARISONS[$node::class]) => $this->runtime('compare', [new Scalar\String_(self::COMPARISONS[$node::class]), $node->left, $node->right]),
            $node instanceof Expr\Isset_ => $this->isset($node),
            $node instanceof Expr\Empty_ => (($node->expr instanceof Expr\Variable) ? $node : new Expr\BooleanNot($node->expr)),
            $node instanceof ArrayItem && $node->unpack => new ArrayItem($this->runtime('spread', [$node->value]), null, false, [], true),
            $node instanceof Expr\Assign && ($node->var instanceof Expr\List_ || $node->var instanceof Expr\Array_) => new Expr\Assign($node->var, $this->runtime('destructure', [$node->expr])),
            $node instanceof Stmt\Foreach_ => $this->foreachLoop($node),
            default => null,
        };
    }

    private function collect(Node $node): void
    {
        if ($this->references === null) {
            return;
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $function = strtolower(ltrim($node->name->toString(), '\\'));
            $this->references->add(TemplateReferences::FUNCTION, ltrim($node->name->toString(), '\\'), $this->line);
            $first = $node->args[0] ?? null;
            if ($first instanceof Arg && $first->name === null && $first->value instanceof Scalar\String_) {
                if ($function === 'route') {
                    $this->references->add(TemplateReferences::ROUTE, $first->value->value, $this->line);
                } elseif (in_array($function, ['__', 'trans', 'trans_choice'], true)) {
                    $this->references->add(TemplateReferences::TRANSLATION, $first->value->value, $this->line);
                }
            }
        } elseif ($node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $this->references->add(TemplateReferences::STATIC_METHOD, ltrim($node->class->toString(), '\\'), $this->line, $node->name->toString());
        } elseif ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier && strtolower($node->name->toString()) !== 'class') {
            $this->references->add(TemplateReferences::CLASS_CONSTANT, ltrim($node->class->toString(), '\\'), $this->line, $node->name->toString());
        } elseif ($node instanceof Expr\ConstFetch && !in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true)) {
            $this->references->add(TemplateReferences::CONSTANT, ltrim($node->name->toString(), '\\'), $this->line);
        }
    }

    private function forbidden(Node $node): ?string
    {
        return match (true) {
            $node instanceof Expr\Eval_ => 'eval()',
            $node instanceof Expr\Include_ => 'include/require',
            $node instanceof Expr\ShellExec => 'shell execution (backticks)',
            $node instanceof Expr\Exit_ => 'exit/die',
            $node instanceof Expr\New_ => 'object instantiation (new)',
            $node instanceof Expr\Clone_ => 'clone',
            $node instanceof Expr\Closure, $node instanceof Expr\ArrowFunction => 'closures',
            $node instanceof Expr\Yield_, $node instanceof Expr\YieldFrom => 'yield',
            $node instanceof Expr\Throw_ => 'throw',
            $node instanceof Expr\Print_ => 'print (unescaped output)',
            $node instanceof Expr\ErrorSuppress => 'error suppression (@)',
            $node instanceof Expr\AssignRef => 'assignment by reference',
            $node instanceof Expr\StaticCall && (!$node->class instanceof Name || $node->class->isSpecialClassName()) => 'dynamic or relative static calls',
            $node instanceof Expr\StaticCall && !$node->name instanceof Identifier => 'dynamic static method names',
            $node instanceof Expr\StaticPropertyFetch => 'static properties',
            $node instanceof Scalar\MagicConst => 'magic constants',
            $node instanceof Cast\Object_ => '(object) casts',
            $node instanceof Cast\Unset_ => '(unset) casts',
            $node instanceof Cast\Void_ => '(void) casts',
            $node instanceof BinaryOp\Pipe => 'the pipe operator',
            $node instanceof Node\VariadicPlaceholder, $node instanceof Node\ArgPlaceholder => 'first-class callables and partial application',
            $node instanceof Expr\Error => 'invalid expression',
            $node instanceof Expr\Variable && !is_string($node->name) => 'variable variables',
            $node instanceof Expr\Variable && self::isReservedVariable($node->name) => 'reserved variable $'.$node->name,
            $node instanceof Expr\FuncCall && !$node->name instanceof Name => 'dynamic function calls ($callable())',
            ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && !$node->name instanceof Identifier => 'dynamic method names',
            ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) && !$node->name instanceof Identifier => 'dynamic property names',
            $node instanceof Expr\ClassConstFetch && (!$node->class instanceof Name || $node->class->isSpecialClassName() || !$node->name instanceof Identifier) => 'dynamic or relative class constants',
            $node instanceof Expr\Instanceof_ && $node->class instanceof Name && $node->class->isSpecialClassName() => 'self/static/parent',
            $node instanceof Expr\ArrayDimFetch && $node->dim === null => 'array append',
            $node instanceof Arg && ($node->unpack || $node->byRef) => 'argument unpacking or by-reference arguments',
            $node instanceof ArrayItem && $node->byRef => 'array items by reference',
            $node instanceof Stmt && !$node instanceof Stmt\Expression && !$node instanceof Stmt\Foreach_ && !$node instanceof Stmt\For_ => 'statements',
            default => null,
        };
    }

    public static function isReservedVariable(string $name): bool
    {
        return str_starts_with($name, '__') || in_array($name, self::RESERVED_VARIABLES, true);
    }

    private function assertAssignable(Expr $target, bool $allowDestructuring): void
    {
        if ($target instanceof Expr\Variable) {
            return;
        }
        if ($allowDestructuring && ($target instanceof Expr\List_ || $target instanceof Expr\Array_)) {
            foreach ($target->items as $item) {
                if ($item === null) {
                    continue;
                }
                if ($item->byRef || $item->unpack) {
                    break;
                }
                $this->assertAssignable($item->value, true);
            }

            return;
        }

        throw InvalidSandboxTemplateException::forbiddenConstruct('writing to properties, array offsets or non-variables', $this->line);
    }

    private function markQuiet(Expr $expression, bool $root): void
    {
        if ($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch || $expression instanceof Expr\ArrayDimFetch) {
            $expression->setAttribute('sb_quiet', true);
            $this->markQuiet($expression->var, false);
        } elseif ($expression instanceof Expr\Variable && !$root) {
            $expression->setAttribute('sb_quiet_base', true);
        }
    }

    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $node): Expr
    {
        assert($node->name instanceof Identifier);
        $nullsafe = $node instanceof Expr\NullsafeMethodCall || $node->var->getAttribute('sb_nullsafe') === true;

        return $this->runtime($nullsafe ? 'callNullsafe' : 'call', [$node->var, new Scalar\String_($node->name->toString()), $this->argumentArray($node->args)], null, $nullsafe);
    }

    private function staticCall(Expr\StaticCall $node): Expr
    {
        assert($node->class instanceof Name && $node->name instanceof Identifier);
        return $this->runtime('staticCall', [new Scalar\String_(ltrim($node->class->toString(), '\\')), new Scalar\String_($node->name->toString()), $this->argumentArray($node->args)]);
    }

    private function propertyFetch(Expr\NullsafePropertyFetch|Expr\PropertyFetch $node): Expr
    {
        assert($node->name instanceof Identifier);
        $nullsafe = $node instanceof Expr\NullsafePropertyFetch || $node->var->getAttribute('sb_nullsafe') === true;
        return $this->runtime(match (true) {
            $node->getAttribute('sb_quiet') === true => 'propQuiet', $nullsafe => 'propNullsafe', default => 'prop'
        }, [$node->var, new Scalar\String_($node->name->toString())], null, $nullsafe);
    }

    private function offsetFetch(Expr\ArrayDimFetch $node): Expr
    {
        assert($node->dim !== null);
        $nullsafe = $node->var->getAttribute('sb_nullsafe') === true;
        return $this->runtime(($node->getAttribute('sb_quiet') === true || $nullsafe) ? 'offsetQuiet' : 'offset', [$node->var, $node->dim], null, $nullsafe);
    }

    private function classConstant(Expr\ClassConstFetch $node): Expr
    {
        assert($node->class instanceof Name && $node->name instanceof Identifier);
        $class = ltrim($node->class->toString(), '\\');

        if (strtolower($node->name->toString()) === 'class') {
            return new Scalar\String_($class);
        }

        return $this->runtime('classConst', [new Scalar\String_($class), new Scalar\String_($node->name->toString())]);
    }

    private function constantFetch(Expr\ConstFetch $node): Expr
    {
        $name = ltrim($node->name->toString(), '\\');
        if (in_array(strtolower($name), ['true', 'false', 'null'], true)) {
            return $this->constant(strtolower($name));
        }

        return $this->runtime('constant', [new Scalar\String_($name)]);
    }

    private function interpolated(Scalar\InterpolatedString $node): Expr
    {
        $result = null;
        foreach ($node->parts as $part) {
            $piece = $part instanceof Node\InterpolatedStringPart ? new Scalar\String_($part->value) : $this->stringify($part);
            $result = $result === null ? $piece : new BinaryOp\Concat($result, $piece);
        }

        return $result ?? new Scalar\String_('');
    }

    private function stringify(Expr $expression, bool $force = false): Expr
    {
        if (!$force && ($expression instanceof Scalar || $expression instanceof BinaryOp\Concat || ($expression instanceof Expr\MethodCall && $expression->getAttribute('sb_generated') === 'str'))) {
            return $expression;
        }
        return $this->runtime('str', [$expression]);
    }

    private function isset(Expr\Isset_ $node): Expr
    {
        $checks = [];
        $native = [];
        foreach ($node->vars as $var) {
            if ($var instanceof Expr\Variable) {
                $native[] = $var;
            } else {
                $checks[] = new BinaryOp\NotIdentical($var, $this->constant('null'));
            }
        }

        if ($native !== []) {
            array_unshift($checks, new Expr\Isset_($native));
        }
        $result = array_shift($checks);
        foreach ($checks as $check) {
            $result = new BinaryOp\BooleanAnd($result, $check);
        }
        assert($result instanceof Expr);

        return $result;
    }

    private function foreachLoop(Stmt\Foreach_ $node): Stmt\Foreach_
    {
        $node->expr = $this->runtime('iterate', [$node->expr, $this->constant(($node->valueVar instanceof Expr\List_ || $node->valueVar instanceof Expr\Array_) ? 'true' : 'false')]);
        return $node;
    }

    /** @param array<Arg|Node\VariadicPlaceholder> $arguments */
    private function argumentArray(array $arguments): Expr\Array_
    {
        $items = [];
        foreach ($arguments as $argument) {
            if (!$argument instanceof Arg) {
                throw InvalidSandboxTemplateException::forbiddenConstruct('first-class callables', $this->line);
            }
            $key = $argument->name !== null ? new Scalar\String_($argument->name->toString()) : null;
            $items[] = new ArrayItem($argument->value, $key);
        }

        return new Expr\Array_($items, ['kind' => Expr\Array_::KIND_SHORT]);
    }

    /** @param list<Expr> $arguments */
    private function runtime(string $method, array $arguments, ?Node $original = null, bool $nullsafe = false): Expr\MethodCall
    {
        $call = new Expr\MethodCall(new Expr\Variable(self::RUNTIME_VARIABLE), new Identifier($method), array_map(static fn (Expr $argument): Arg => new Arg($argument), $arguments));
        $call->setAttribute('sb_generated', $method);
        if ($nullsafe) {
            $call->setAttribute('sb_nullsafe', true);
        }
        return $call;
    }

    private function constant(string $name): Expr\ConstFetch
    {
        return new Expr\ConstFetch(new Name($name));
    }

    private function nameOf(Node $name): string
    {
        assert($name instanceof Name);
        return $name->toString();
    }
}
