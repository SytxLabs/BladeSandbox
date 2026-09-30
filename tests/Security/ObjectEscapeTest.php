<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Security;

use ArrayObject;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenPropertyException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Tests\Fixtures\Models\User;

final class ObjectEscapeTest extends SecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createUsers();
    }

    private function user(): User
    {
        return User::query()->firstOrFail();
    }

    #[DataProvider('modelPayloads')]
    public function testModelsAreRestricted(string $template, string $exception): void
    {
        $this->assertBlocked($template, ['user' => $this->user(), 'method' => 'delete'], $exception);
        $this->assertSame(1, $this->userCount());
        $this->assertSame('Alice', $this->user()->name);
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function modelPayloads(): iterable
    {
        yield 'delete' => ['{{ $user->delete() }}', ForbiddenMethodException::class];
        yield 'forceDelete' => ['{{ $user->forceDelete() }}', ForbiddenMethodException::class];
        yield 'save' => ['{{ $user->save() }}', ForbiddenMethodException::class];
        yield 'update' => ["{{ \$user->update(['name' => 'x']) }}", ForbiddenMethodException::class];
        yield 'getConnection' => ['{{ $user->getConnection() }}', ForbiddenMethodException::class];
        yield 'newQuery' => ['{{ $user->newQuery()->delete() }}', ForbiddenMethodException::class];
        yield 'query builder via __call' => ["{{ \$user->where('id', 1)->delete() }}", ForbiddenMethodException::class];
        yield 'magic __call' => ["{{ \$user->__call('delete', []) }}", ForbiddenMethodException::class];
        yield 'toArray' => ['{{ $user->toArray() }}', ForbiddenMethodException::class];
        yield 'password attribute' => ['{{ $user->password }}', ForbiddenPropertyException::class];
        yield 'protected attributes array' => ['{{ $user->attributes }}', ForbiddenPropertyException::class];
        yield 'array access on model' => ["{{ \$user['password'] }}", ForbiddenMethodException::class];
        yield 'string conversion (toJson)' => ['{{ $user }}', ForbiddenMethodException::class];
        yield 'implicit string conversion via concat' => ["{{ 'x' . \$user }}", ForbiddenMethodException::class];
        yield 'implicit string conversion via interpolation' => ['{{ "x{$user}" }}', ForbiddenMethodException::class];
        yield 'implicit string conversion via comparison' => ["{{ \$user == 'x' }}", ForbiddenMethodException::class];
        yield 'array cast exposes privates' => ['{{ count((array) $user) }}', ForbiddenMethodException::class];
        yield 'iteration of plain object' => ['@foreach($user as $k => $v){{ $k }}@endforeach', ForbiddenMethodException::class];
        yield 'destructuring array access' => ['{{ [$a] = $user }}', ForbiddenMethodException::class];
        yield 'destructuring in foreach' => ['@foreach([$user] as [$a]){{ $a }}@endforeach', ForbiddenMethodException::class];
        yield 'json of model' => ['@json($user)', ForbiddenMethodException::class];
        yield 'dynamic method name' => ['{{ $user->{$method}() }}', InvalidSandboxTemplateException::class];
        yield 'dynamic property name' => ['{{ $user->{$method} }}', InvalidSandboxTemplateException::class];
        yield 'property write' => ["{{ \$user->name = 'x' }}", InvalidSandboxTemplateException::class];
        yield 'static call on instance' => ['{{ $user::query() }}', InvalidSandboxTemplateException::class];
        yield 'spread of object' => ['{{ count([...$user]) }}', ForbiddenMethodException::class];
    }

    public function testExplicitMemberAllowlistForModels(): void
    {
        $sandbox = $this->sandbox()->allowMethod(User::class, 'getName')->allowProperty(User::class, 'name');

        $this->assertSame('Alice Alice', $sandbox->render('{{ $user->name }} {{ $user->getName() }}', ['user' => $this->user()]));

        foreach (['{{ $user->delete() }}', '{{ $user->save() }}', '{{ $user->forceDelete() }}', '{{ $user->email }}'] as $template) {
            $this->assertBlocked($template, ['user' => $this->user()], sandbox: $sandbox);
        }
        $this->assertSame(1, $this->userCount());
    }

    public function testMagicMethodsCanNeverBeAllowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sandbox()->allowMethod(User::class, '__call');
    }

    public function testWildcardMethodRuleStillExcludesMagicMethods(): void
    {
        $sandbox = $this->sandbox()->allowMethod(User::class, '*');

        $this->assertBlocked("{{ \$user->__call('delete', []) }}", ['user' => $this->user()], ForbiddenMethodException::class, $sandbox);
        $this->assertBlocked("{{ \$user->__get('password') }}", ['user' => $this->user()], ForbiddenMethodException::class, $sandbox);
    }

    public function testNonPublicMethodsAreNotRoutedToCall(): void
    {
        $object = new class
        {
            public bool $called = false;

            public function __call(string $name, array $arguments): string
            {
                return 'magic '.$name;
            }

            private function secret(): string
            {
                $GLOBALS['__blade_sandbox_side_effect'] = true;

                return 'secret';
            }
        };

        $sandbox = $this->sandbox()->allowMethod($object::class, ['secret', 'virtual']);

        $this->assertBlocked('{{ $o->secret() }}', ['o' => $object], ForbiddenMethodException::class, $sandbox);
        // Explicitly allowed virtual (__call) methods work.
        $this->assertSame('magic virtual', $sandbox->render('{{ $o->virtual() }}', ['o' => $object]));
    }

    public function testCollectionsDefaultReadApi(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame('3 1 a,b,c 2', $this->sandbox()->render("{{ \$c->count() }} {{ \$c->first() }} @foreach(\$c->keys() as \$k){{ \$k }}{{ \$loop->last ? '' : ',' }}@endforeach {{ \$c['b'] }}", ['c' => $collection]));
        $this->assertSame('123', $this->sandbox()->render('@foreach($c as $v){{ $v }}@endforeach', ['c' => LazyCollection::make(fn () => yield from [1, 2, 3])]));
    }

    #[DataProvider('collectionPayloads')]
    public function testCollectionEscapes(string $template): void
    {
        Collection::macro('evilMacro', static fn () => $GLOBALS['__blade_sandbox_side_effect'] = true);

        $this->assertBlocked($template, ['c' => new Collection(['id']), 'models' => User::all()]);
    }

    /** @return iterable<string, array{string}> */
    public static function collectionPayloads(): iterable
    {
        yield 'map string callable' => ["{{ \$c->map('system') }}"];
        yield 'filter string callable' => ["{{ \$c->filter('system') }}"];
        yield 'each' => ["{{ \$c->each('system') }}"];
        yield 'reduce' => ["{{ \$c->reduce('system') }}"];
        yield 'first with callable' => ["{{ \$c->first('system') }}"];
        yield 'first with array callable' => ["{{ \$c->first(['Illuminate\\Support\\Facades\\Artisan', 'call']) }}"];
        yield 'pluck bypasses item policy' => ["{{ \$models->pluck('password') }}"];
        yield 'toArray serialises models' => ['{{ $models->toArray() }}'];
        yield 'implode stringifies models' => ["{{ \$models->implode(',') }}"];
        yield 'toJson' => ['{{ $models->toJson() }}'];
        yield 'macro call' => ['{{ $c->evilMacro() }}'];
        yield 'pipe' => ["{{ \$c->pipe('system') }}"];
        yield 'sortBy with callable string' => ["{{ \$c->sortBy('system') }}"];
    }

    public function testCollectionItemsKeepTheirOwnPolicy(): void
    {
        $this->assertBlocked('@foreach($models as $m){{ $m->delete() }}@endforeach', ['models' => User::all()], ForbiddenMethodException::class);
        $this->assertBlocked('{{ $models->first()->delete() }}', ['models' => User::all()], ForbiddenMethodException::class);
        $this->assertSame(1, $this->userCount());
    }

    public function testClosuresAndInvokablesInDataCannotBeCalled(): void
    {
        $closure = static function (): void {
            $GLOBALS['__blade_sandbox_side_effect'] = true;
        };
        $invokable = new class
        {
            public function __invoke(): void
            {
                $GLOBALS['__blade_sandbox_side_effect'] = true;
            }
        };

        $this->assertBlocked('{{ $fn() }}', ['fn' => $closure], InvalidSandboxTemplateException::class);
        $this->assertBlocked('{{ $fn->__invoke() }}', ['fn' => $closure], ForbiddenMethodException::class);
        $this->assertBlocked('{{ $fn->call($fn) }}', ['fn' => $closure], ForbiddenMethodException::class);
        $this->assertBlocked('{{ $c->map($fn) }}', ['fn' => $closure, 'c' => collect([1])]);
        $this->assertBlocked('{{ $c->first($fn) }}', ['fn' => $invokable, 'c' => collect([1])]);
        $this->assertBlocked('{{ $fn }}', ['fn' => $closure], ForbiddenMethodException::class);
    }

    public function testHtmlableViewsPassedAsDataAreNotRenderedOutsideTheSandbox(): void
    {
        $this->assertBlocked('{{ $view }}', ['view' => view('secret')], ForbiddenMethodException::class);
        $this->assertBlocked('{{ $view->render() }}', ['view' => view('secret')], ForbiddenMethodException::class);
    }

    public function testReflectionObjectsPassedAsDataAreInert(): void
    {
        $reflection = new ReflectionClass(User::class);
        $this->assertBlocked("{{ \$r->getMethod('delete')->invoke(\$u) }}", ['r' => $reflection, 'u' => $this->user()], ForbiddenMethodException::class);
        $this->assertBlocked('{{ $r->newInstance() }}', ['r' => $reflection], ForbiddenMethodException::class);
        $this->assertSame(1, $this->userCount());
    }

    public function testArrayObjectAndStdClassDefaults(): void
    {
        $std = (object) ['a' => 1, 'nested' => (object) ['b' => 2]];
        $this->assertSame('1 2', $this->sandbox()->render('{{ $o->a }} {{ $o->nested->b }}', ['o' => $std]));
        $this->assertSame('x', $this->sandbox()->render("{{ \$o['k'] }}", ['o' => new ArrayObject(['k' => 'x'])]));
    }

    public function testEnumsAndClassConstants(): void
    {
        $this->assertSame('b', $this->sandbox()->render('{{ $e->value }}', ['e' => Status::Active]));
        $this->assertSame('b', $this->sandbox()->render('{{ $e }}', ['e' => Status::Active]));
        $this->assertBlocked('{{ \SytxLabs\BladeSandbox\Tests\Security\Status::Active->value }}', [], ForbiddenPropertyException::class);
        $this->assertSame('b', $this->sandbox()->allowClassConstant(Status::class, 'Active')->render('{{ \SytxLabs\BladeSandbox\Tests\Security\Status::Active->value }}'));
        $this->assertSame('SytxLabs\BladeSandbox\Tests\Security\Status', html_entity_decode($this->sandbox()->render('{{ \SytxLabs\BladeSandbox\Tests\Security\Status::class }}')));
    }
}

enum Status: string
{
    case Active = 'b';
}
