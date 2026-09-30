<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class SectionsStacksComponentsTest extends TestCase
{
    public function testAppendSectionAddsToAnAlreadyDefinedSection(): void
    {
        $html = $this->sandbox()->render("@section('x')A @endsection @section('x')B @append @yield('x')");
        $this->assertSame('AB', trim(preg_replace('/\s+/', '', $html) ?? ''));
    }

    public function testParentPlaceholderIsReplacedByTheEarlierSectionContent(): void
    {
        $template = "@section('x')A @parent B @endsection @section('x')C @parent D @endsection @yield('x')";
        $html = $this->sandbox()->render($template);
        $this->assertSame('A C D B', trim(preg_replace('/\s+/', ' ', $html) ?? ''));
    }

    public function testOverwriteReplacesAnEarlierSection(): void
    {
        $html = $this->sandbox()->render("@section('x')A @endsection @section('x')B @overwrite @yield('x')");
        $this->assertSame('B', trim(preg_replace('/\s+/', '', $html) ?? ''));
    }

    public function testSectionAndStackNamesAcceptBackedEnumsAndRejectOtherTypes(): void
    {
        $html = $this->sandbox()->render('@section($name)hi @endsection @yield($name)', ['name' => SectionName::Title]);
        $this->assertSame('hi', trim($html));

        try {
            $this->sandbox()->render('@section($name)hi @endsection', ['name' => ['not', 'a', 'name']]);
            $this->fail('a non-string, non-int section name must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('must be strings', $exception->getMessage());
        }
    }

    public function testShowStopsAndImmediatelyYieldsTheSection(): void
    {
        $this->assertSame('Hi', trim($this->sandbox()->render("@section('title')Hi @show")));
    }

    public function testHasSectionAndSectionMissing(): void
    {
        $template = "@section('x')y @endsection @hassection('x') yes @endif |@sectionmissing('y') missing @endif";
        $this->assertSame('yes | missing', trim(preg_replace('/\s+/', ' ', $this->sandbox()->render($template)) ?? ''));
    }

    public function testPushSingleLineFormAndStack(): void
    {
        $this->assertSame('a', trim($this->sandbox()->render("@push('scripts', 'a') @stack('scripts')")));
    }

    public function testPrependBlockAndInlineFormsAndEndprepend(): void
    {
        $template = "@push('scripts', 'B') @prepend('scripts')A @endprepend @prepend('scripts', 'Z') @stack('scripts')";
        // Each @prepend puts its content before whatever is already there, so the last one evaluated ends up first.
        $this->assertSame('ZAB', trim(preg_replace('/\s+/', '', $this->sandbox()->render($template)) ?? ''));
    }

    public function testStopPrependWithoutStartingIsRejected(): void
    {
        try {
            $this->sandbox()->render('@endprepend');
            $this->fail('@endprepend without @prepend must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('prepend', $exception->getMessage());
        }
    }

    public function testStackFallsBackToTheDefaultWhenNothingWasPushed(): void
    {
        $this->assertSame('fallback', trim($this->sandbox()->render("@stack('unused', 'fallback')")));
    }

    public function testStopSectionWithoutStartingIsRejected(): void
    {
        foreach (['@endsection', '@append', '@overwrite'] as $directive) {
            try {
                $this->sandbox()->render($directive);
                $this->fail($directive.' without @section must be rejected');
            } catch (SandboxException $exception) {
                $this->assertStringContainsString('section', $exception->getMessage());
            }
        }
    }

    public function testShowWithoutAStartedSectionYieldsNothing(): void
    {
        $this->assertSame('', $this->sandbox()->render('@show'));
    }

    public function testStopPushWithoutStartingIsRejected(): void
    {
        try {
            $this->sandbox()->render('@endpush');
            $this->fail('@endpush without @push must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('push', $exception->getMessage());
        }
    }

    public function testEndcomponentWithoutAComponentIsRejectedAtCompileTime(): void
    {
        try {
            $this->sandbox()->render('@endcomponent');
            $this->fail('@endcomponent without @component must be rejected');
        } catch (InvalidSandboxTemplateException $exception) {
            $this->assertStringContainsString('component', $exception->getMessage());
        }
    }

    public function testSlotOutsideAComponentIsRejected(): void
    {
        try {
            $this->sandbox()->render("@slot('x')y@endslot");
            $this->fail('@slot outside a component must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('inside components', $exception->getMessage());
        }
    }

    public function testEndSlotWithoutStartingIsRejected(): void
    {
        $sandbox = $this->sandbox()->allowView('deployer::components.card')->allowComponent('deployer::card');
        try {
            $sandbox->render("@component('deployer::components.card', ['title' => 't']) @endslot @endcomponent");
            $this->fail('@endslot without @slot must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('slot', $exception->getMessage());
        }
    }

    public function testInvalidSlotNameIsRejected(): void
    {
        $sandbox = $this->sandbox()->allowView('deployer::components.card')->allowComponent('deployer::card');
        try {
            $sandbox->render("@component('deployer::components.card', ['title' => 't']) @slot('attributes')x@endslot @endcomponent");
            $this->fail('reserved slot name must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('Invalid slot name', $exception->getMessage());
        }
    }

    public function testPropsFallsBackToADirectScopeVariableAndTreatsANonBagAttributesAsEmpty(): void
    {
        // No 'attributes' key is provided at all here, so props() must build a fresh, empty bag itself.
        $this->assertSame('Hello', trim($this->sandbox()->render("@props(['label' => 'x']){{ \$label }}", ['label' => 'Hello'])));

        // 'attributes' is present but not a bag: props() must fall back to a fresh bag instead of using it.
        $this->assertSame('Hello', trim($this->sandbox()->render("@props(['label' => 'x']){{ \$label }}", ['label' => 'Hello', 'attributes' => 'not-a-bag'])));
    }

    public function testAwareWithoutAnEnclosingComponentUsesTheDefault(): void
    {
        $this->assertSame('fallback', trim($this->sandbox()->render("@aware(['theme' => 'fallback']){{ \$theme }}")));
    }

    private function footerSandbox(): Sandbox
    {
        return $this->sandbox()->allowView('deployer::partials.footer')->allowMethod(DeploymentDTO::class, 'getLabel');
    }

    public function testIncludeWhenAndIncludeUnlessRenderTheirTrueBranch(): void
    {
        $sandbox = $this->footerSandbox();
        $data = ['deployment' => new DeploymentDTO()];

        $this->assertStringContainsString('<footer>', $sandbox->render("@includeWhen(true, 'deployer::partials.footer')", $data));
        $this->assertSame('', trim($sandbox->render("@includeWhen(false, 'deployer::partials.footer')", $data)));
        $this->assertStringContainsString('<footer>', $sandbox->render("@includeUnless(false, 'deployer::partials.footer')", $data));
        $this->assertSame('', trim($sandbox->render("@includeUnless(true, 'deployer::partials.footer')", $data)));
    }

    public function testIncludeFirstUsesTheFirstExistingViewOrThrows(): void
    {
        $sandbox = $this->footerSandbox()->allowViewNamespace('deployer');
        $data = ['deployment' => new DeploymentDTO()];

        $this->assertStringContainsString('<footer>', $sandbox->render("@includeFirst(['deployer::missing', 'deployer::partials.footer'])", $data));

        try {
            $sandbox->render("@includeFirst(['deployer::missing', 'deployer::also-missing'])");
            $this->fail('includeFirst with no existing view must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('None of the views', $exception->getMessage());
        }
    }

    public function testEachRendersEveryItemAndSupportsACustomEmptyView(): void
    {
        $sandbox = $this->footerSandbox();
        $data = ['items' => [new DeploymentDTO(), new DeploymentDTO()]];

        $html = $sandbox->render("@each('deployer::partials.footer', \$items, 'deployment')", $data);
        $this->assertSame(2, substr_count($html, '<footer>'));

        $empty = $sandbox->allowView('deployer::partials.empty-each')->render("@each('deployer::partials.footer', \$empty, 'deployment', 'deployer::partials.empty-each')", ['empty' => []]);
        $this->assertStringContainsString('<empty-each>', $empty);
    }

    public function testEachRejectsAnInvalidVariableName(): void
    {
        try {
            $this->footerSandbox()->render("@each('deployer::partials.footer', \$items, '123bad')", ['items' => []]);
            $this->fail('invalid @each variable name must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('@each variable', $exception->getMessage());
        }
    }

    public function testAwareReadsAValueSetByAnAncestorComponent(): void
    {
        $html = $this->sandbox()->allowComponent('deployer::aware-*')
            ->render('<x-deployer::aware-parent theme="dark" />');

        $this->assertStringContainsString('<span>dark</span>', $html);
    }

    public function testUnbalancedSectionsAreRejectedAtRenderTime(): void
    {
        $this->expectException(SandboxException::class);
        $this->sandbox()->allowDirective('section')->render("@section('a') never closed");
    }

    public function testRenderingAComponentThatWasNeverStartedFails(): void
    {
        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('not started');
        $this->sandbox()->runtime()->renderComponent();
    }
}

enum SectionName: string
{
    case Title = 'x';
}
