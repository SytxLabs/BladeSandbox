<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenPropertyException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO;
use SytxLabs\BladeSandbox\Tests\Fixtures\Models\User;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class VarDirectiveTest extends TestCase
{
    public function testAssignsLocalVariablesWithoutOutput(): void
    {
        $sandbox = $this->sandbox()->allowProperty(User::class, 'name');
        $user = new User(['name' => 'Alice']);

        $this->assertSame('<p>Alice</p>', $sandbox->render('@var($name = $user->name)<p>{{ $name }}</p>', ['user' => $user]));
        $this->assertSame('Alice / 3', $sandbox->render("@var(\$name = \$user->name, \$count = 1 + 2)\n{{ \$name }} / {{ \$count }}", ['user' => $user]));
    }

    public function testCompoundAssignmentsDestructuringAndLoops(): void
    {
        $template = "@var(\$total = 0)@foreach(\$items as \$i)@var(\$total += \$i)@endforeach{{ \$total }} @var([\$a, \$b] = \$pair){{ \$a }}{{ \$b }} @var(\$label = 'x' . \$a)\n{{ \$label }}";

        $this->assertSame('6 12 x1', $this->sandbox()->render($template, ['items' => [1, 2, 3], 'pair' => [1, 2]]));
    }

    public function testValuesFromDtos(): void
    {
        $this->assertSame('api: running', $this->sandbox()->allowDto(DeploymentDTO::class)
            ->render('@var($label = $d->getLabel()){{ $label }}', ['d' => new DeploymentDTO()]));
    }

    public function testRightHandSideIsSandboxed(): void
    {
        $user = new User(['name' => 'Alice', 'password' => 'secret']);

        foreach ([
            '@var($x = $user->password)' => ForbiddenPropertyException::class,
            "@var(\$x = system('id'))" => ForbiddenFunctionException::class,
            "@var(\$x = eval('1'))" => InvalidSandboxTemplateException::class,
        ] as $template => $exception) {
            try {
                $this->sandbox()->render($template, ['user' => $user]);
                $this->fail('Expected '.$exception.' for '.$template);
            } catch (SecurityViolationException $violation) {
                $this->assertInstanceOf($exception, $violation, $template);
            }
        }
    }

    public function testOnlyPlainLocalVariablesCanBeAssigned(): void
    {
        foreach ([
            '@var($user->name = 1)',
            "@var(\$data['x'] = 1)",
            '@var($__sandbox = 1)',
            '@var($__env = 1)',
            '@var($this = 1)',
            '@var($GLOBALS = 1)',
            '@var(${"a"} = 1)',
            '@var($a = &$b)',
            '@var($a)',
            '@var(1 + 1)',
            '@var(x: $a = 1)',
            '@var($a = 1, system("id"))',
        ] as $template) {
            try {
                $this->sandbox()->render($template, ['user' => new User(), 'data' => [], 'b' => 1]);
                $this->fail('Template must be rejected: '.$template);
            } catch (InvalidSandboxTemplateException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTextAfterTheDirectiveIsOnlyText(): void
    {
        $this->assertSame('; system("id"); 1', $this->sandbox()->render('@var($a = 1); system("id"); {{ $a }}'));
    }

    public function testLiteralVarWithoutParenthesesAndDeny(): void
    {
        $this->assertSame('/** @var string */ user@var', $this->sandbox()->render('/** @var string */ user@var'));

        $this->expectException(ForbiddenDirectiveException::class);
        $this->sandbox()->denyDirective('var')->render('@var($a = 1)');
    }

    public function testValidationReportsProblemsInVar(): void
    {
        $result = $this->sandbox()->validate("ok\n@var(\$x = exec('id'))");

        $this->assertSame('exec()', $result->violations()[0]->subject);
        $this->assertSame(2, $result->violations()[0]->line);
    }

    public function testSemicolonSeparatedAndMultiLine(): void
    {
        $sandbox = $this->sandbox()->allowProperty(User::class, 'name');
        $data = ['user' => new User(['name' => 'Alice'])];

        $this->assertSame('3', $sandbox->render('@var($a = 1; $b = 2){{ $a + $b }}'));
        $this->assertSame('3', $sandbox->render('@var($a = 1; $b = 2;){{ $a + $b }}'));

        $template = <<<'BLADE'
            @var(
                $name = $user->name;
                $label = 'x);y' . ';';   // strings and comments may contain ; and )
                $total = 0, $count = 2;
                /* block ; comment */
                $total += $count;
            )
            {{ $name }}|{{ $label }}|{{ $total }}
            BLADE;

        $this->assertSame('Alice|x);y;|2', trim($sandbox->render($template, $data)));
    }

    public function testSemicolonFormKeepsAllRestrictions(): void
    {
        foreach ([
            '@var($a = 1; exec("id"))',
            '@var($a = 1; echo $a)',
            '@var($a = 1; ?> <?php system("id"); ?>)',
            '@var($a = 1; function x() {})',
            '@var(;)',
            '@var($a = 1; $this->x = 2)',
        ] as $template) {
            try {
                $this->sandbox()->render($template);
                $this->fail($template.' must be rejected.');
            } catch (InvalidSandboxTemplateException|SecurityViolationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ForbiddenFunctionException::class);
        $this->sandbox()->render('@var($a = 1; $b = exec("id"))');
    }

    public function testErrorsReportTheLineOfTheStatement(): void
    {
        $result = $this->sandbox()->validate("<p>x</p>\n@var(\n    \$a = 1;\n    \$b = exec('id');\n)");

        $this->assertTrue($result->fails());
        $this->assertSame(4, $result->violations()[0]->line);
    }

    public function testBlockFormWithEndvar(): void
    {
        $sandbox = $this->sandbox()->allowProperty(User::class, 'name');

        $template = <<<'BLADE'
            <p>before</p>
            @var
                $name = $user->name;
                $total = 0, $count = 2;   // ; and , can be mixed
                $total += $count;
            @endvar
            <p>{{ $name }}|{{ $total }}</p>
            BLADE;

        $this->assertSame("<p>before</p>\n<p>Alice|2</p>", $sandbox->render($template, ['user' => new User(['name' => 'Alice'])]));
        $this->assertSame('1', $sandbox->render("@var   \r\n\$a = 1;\n@endvar{{ \$a }}"));
    }

    public function testBlockFormKeepsRestrictionsAndReportsLines(): void
    {
        $result = $this->sandbox()->validate("<p>x</p>\n@var\n    \$a = 1;\n    \$b = exec('id');\n@endvar");
        $this->assertTrue($result->fails());
        $this->assertSame(4, $result->violations()[0]->line);

        foreach (["@var\necho 1;\n@endvar", "@var\n\$a = 1; ?> <?php system('id');\n@endvar", "@var\n@endvar", "@var\n\$this->x = 1;\n@endvar"] as $template) {
            try {
                $this->sandbox()->render($template);
                $this->fail($template.' must be rejected.');
            } catch (InvalidSandboxTemplateException|SecurityViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testVarTextIsStillLiteral(): void
    {
        // "@var" followed by text on the same line is documentation text, not a block.
        $this->assertSame('@var string $name', $this->sandbox()->render('@var string $name'));
        $this->assertSame("/** @var int */\n", $this->sandbox()->render("/** @var int */\n"));
    }
}
