# Blade Sandbox for Laravel

[![Run tests](https://github.com/shaunluedeke/BladeSandbox/actions/workflows/tests.yml/badge.svg?style=flat-square)](https://github.com/shaunluedeke/BladeSandbox/actions/workflows/tests.yml)
[![Run tests on Windows](https://github.com/shaunluedeke/BladeSandbox/actions/workflows/tests-windows.yml/badge.svg?style=flat-square)](https://github.com/shaunluedeke/BladeSandbox/actions/workflows/tests-windows.yml)
[![Static analysis](https://github.com/shaunluedeke/BladeSandbox/actions/workflows/static-analysis.yml/badge.svg?style=flat-square)](https://github.com/shaunluedeke/BladeSandbox/actions/workflows/static-analysis.yml)
[![Check code style](https://github.com/shaunluedeke/BladeSandbox/actions/workflows/code-style.yml/badge.svg?style=flat-square)](https://github.com/shaunluedeke/BladeSandbox/actions/workflows/code-style.yml)
[![Compiler fuzzing](https://github.com/shaunluedeke/BladeSandbox/actions/workflows/fuzz.yml/badge.svg?style=flat-square)](https://github.com/shaunluedeke/BladeSandbox/actions/workflows/fuzz.yml)
[![Latest Version on Packagist](https://poser.pugx.org/sytxlabs/blade-sandbox/v/stable?format=flat-square)](https://packagist.org/packages/sytxlabs/blade-sandbox)
[![Total Downloads](https://poser.pugx.org/sytxlabs/blade-sandbox/downloads?format=flat-square)](https://packagist.org/packages/sytxlabs/blade-sandbox)

`sytxlabs/blade-sandbox` renders **untrusted Blade templates and views** under an explicit security
policy: CMS content, customer templates, e-mail and PDF templates from the database, views of plugins
and external packages (e.g. `plugin::page`) and Livewire views. Like the
[Twig sandbox](https://twig.symfony.com/doc/3.x/sandbox.html) it is **default deny, explicit allow**,
with an API built around Laravel concepts: views, view namespaces, components, directives, DTOs and
Livewire.

```php
use SytxLabs\BladeSandbox\Facades\BladeSandbox;

$html = BladeSandbox::make()
    ->allowDto(ArticleDTO::class)
    ->allowView('plugin::page')
    ->allowView('plugin::partials.*')
    ->renderView('plugin::page', ['article' => $article]);
```

- **No PHP execution.** An own Blade compiler checks every expression structurally on the PHP AST,
  and every operation is mediated at runtime.
- **Nothing is reachable unless allowed:** functions, methods, properties, views, components,
  directives, routes, DTOs and `wire:*` attributes.
- **Every nested view runs in the same sandbox:** includes, layouts, components, dynamic view names.
- **Livewire requests** are checked server-side against the same policy.
- **Operations built in:** limits, isolated and queued rendering, output sanitizers, fallback output,
  output cache, validation, policy learning, author lockout, logging, events and fakes.

> The sandbox controls what a template can do. It does not sanitize HTML unless you enable an output
> sanitizer, and it is only process isolation with `isolated()`. Read [SECURITY.md](SECURITY.md)
> before you render templates written by untrusted people.

## Contents

1. [Installation & compatibility](#1-installation--compatibility)
2. [Policies](#2-policies)
3. [Templates & views](#3-templates--views)
4. [Data: DTOs, objects, helpers, macros](#4-data-dtos-objects-helpers-macros)
5. [Template features](#5-template-features)
6. [Livewire](#6-livewire)
7. [Deny rules](#7-deny-rules)
8. [Output: sanitizers, fallback, text, cache](#8-output-sanitizers-fallback-text-cache)
9. [Checking & authoring](#9-checking--authoring)
10. [Running renders: limits, isolation, queue, logging](#10-running-renders-limits-isolation-queue-logging)
11. [Compiled template cache](#11-compiled-template-cache)
12. [Events, testing & extension points](#12-events-testing--extension-points)
13. [Configuration reference](#13-configuration-reference)
14. [Exceptions](#14-exceptions)
15. [Development](#15-development)

---

## 1. Installation & compatibility

```bash
composer require sytxlabs/blade-sandbox
php artisan vendor:publish --tag=blade-sandbox-config   # optional
```

The service provider and the `BladeSandbox` facade are auto-discovered; `php artisan about` shows a
*SytxLabs Blade Sandbox* section (version, default policy, Livewire, compiled-template cache, bound
namespaces).

**Requirements:** PHP **8.2+** with `ext-ctype`, `ext-dom`, `ext-libxml`, `ext-tokenizer` (standard
builds have them), `illuminate/{support,view,contracts,filesystem}` 10–13 and `nikic/php-parser` ^5.
`laravel/framework` itself is not required.

**Optional packages:** `livewire/livewire` 3 or 4 · `sytxlabs/filesanitizer` (output sanitizing) ·
`illuminate/database` (database templates) · `illuminate/cache` (output cache, author
lockout) · `illuminate/events` (events) · `illuminate/queue` (queued rendering) · `league/commonmark`
(`@markdown`) · `symfony/process` (isolated rendering; installed with laravel/framework).

| Laravel | PHP (package minimum 8.2) | Livewire 3    | Livewire 4   | Without Livewire |
|---------|---------------------------|---------------|--------------|------------------|
| 10.x    | 8.2 – 8.3                 | ✓            | ✓           | ✓               |
| 11.x    | 8.2 – 8.4                 | ✓            | ✓           | ✓               |
| 12.x    | 8.2 – 8.5                 | ✓            | ✓           | ✓               |
| 13.x    | 8.3 – 8.5                 | ✓ (≥ 3.7.11) | ✓ (≥ 4.2.0) | ✓               |

- CI tests every combination on Linux, plus Laravel 13 / Livewire 4 on Windows; tests, Windows,
  PHPStan, code style and fuzzing each have their own workflow (see the badges above). Livewire 2 is not
  supported.
- **Laravel 10 and 11 are end-of-life.** Their final releases have open security advisories this
  package cannot fix, and Composer ≥ 2.9 refuses them unless advisory blocking is disabled (CI does
  that). Use Laravel 12 or 13 in production.
- PHP 8.2 is the minimum because readonly classes (the typical DTO shape) need it and 8.1 is EOL.
- Version-specific code lives only in `src/Compatibility` (view finder, component names) and
  `src/Livewire/*Adapter.php` (Livewire 3 vs. 4).

## 2. Policies

```php
use SytxLabs\BladeSandbox\Facades\BladeSandbox;

$sandbox = BladeSandbox::make();          // denies everything except safe value objects
$sandbox->allowView('plugin::page');      // allow*() mutates the sandbox and returns it

$html = $sandbox->render('<h1>{{ $name }}</h1>', ['name' => 'Shaun']);   // template string
$html = $sandbox->renderView('plugin::page', $data);                    // view
$view = $sandbox->view('plugin::page', $data);                          // Illuminate View instance

$html = BladeSandbox::policy('mail')->renderView('plugin::emails.welcome', $data);  // policy from config
$html = BladeSandbox::render($template, $data);                                       // default / resolved policy
```

`SytxLabs\BladeSandbox\Sandbox` can also be resolved from the container (a new sandbox each time).
Every render takes an immutable snapshot of the policy, so changing a sandbox never affects a running
render. `$sandbox->policy()` returns it (`allowsView()`, `allowsMethod()`, `fingerprint()`, …).

**Config policies** live in `config/blade-sandbox.php` (all keys:
[configuration reference](#13-configuration-reference)):

```php
'policies' => [
    'mail' => [
        'views' => ['plugin::emails.*'],
        'dtos' => [App\DTO\Dto::class],
        'routes' => ['shop.*'],
        'directives' => ['csrf'],
    ],
    'newsletter' => [
        'extends' => 'mail',            // or a list; the parents' deny rules keep winning
        'raw_echo' => true,
    ],
],
```

**Inheritance.** `'extends'` or `$sandbox->extend($otherSandbox, 'policyName')` merges parent
policies: allow and deny rules are united and denies (incl. denied directives) keep winning;
permissions such as raw echo or Alpine apply if any parent grants them; restrictions such as disabled
translations or native DTO conversion apply if any parent sets them; limits, sanitizers and fallback
stay the sandbox's own; cycles are detected.

**Policy per tenant / user.** A resolver picks the sandbox for `BladeSandbox::render()`,
`renderView()` and `forRequest()`:

```php
BladeSandbox::resolvePolicyUsing(fn (?Request $request) => match (true) {
    $request?->user()?->isAdmin() => 'admin',        // policy name from config
    tenant()?->plan === 'pro' => BladeSandbox::policy('pro')->allowMarkdown(),
    default => null,                                 // default policy
});

$html = BladeSandbox::forRequest()->renderView('cms::page', $data);
```

In config: `'policy_resolver' => App\Sandbox\TenantPolicyResolver::class` (a `Contracts\PolicyResolver`).

**Overriding configuration.** The published `config/blade-sandbox.php` only needs the keys you
change: it is deep-merged over the package defaults (groups are merged key by key, lists replace the
default). A package such as a CMS can override settings and policies from its service provider without
touching the application's config:

```php
public function boot(): void
{
    BladeSandbox::configure([                          // deep-merged into config('blade-sandbox')
        'limits' => ['timeout_ms' => 5_000],
        'sanitizers' => ['cms' => CmsSanitizer::class],
        'logging' => ['enabled' => true, 'channel' => 'cms'],
    ]);

    BladeSandbox::definePolicy('cms', [                // new or replaced policy (array or closure)
        'extends' => 'default',
        'view_namespaces' => ['cms'],
        'helpers' => ['safe'],
    ]);

    BladeSandbox::extendPolicy('default', fn (Sandbox $sandbox) => $sandbox->allowDto(PageDTO::class));
}
```

- A code policy wins over a config policy of the same name; `extendPolicy()` adds to either, after its
  own definition (deny rules keep winning). `BladeSandbox::hasPolicy()` checks both.
- Changes via `configure()` or `config()->set('blade-sandbox.…')` apply to sandboxes created afterwards.
- Register them in a service provider, so isolated renders and queue workers have them too.

**Presets.** `Sandbox` is macroable; config `'presets'` applies macros before the other keys:

```php
Sandbox::macro('cms', fn () => $this->allowViewNamespace('cms')->allowDtoNamespace('App\\DTO\\Cms'));
BladeSandbox::make()->cms()->renderView('cms::page', $data);   // config: 'presets' => ['cms']
```

## 3. Templates & views

**Blade features.** Echoes (`{{ }}` escaped), control structures incl. `$loop`, layouts, sections,
stacks and components work as usual. `{!! !!}` needs `allowRawEcho()`. See [directives](#directives).

**View resolution.** Views come from the normal Laravel view finder: `resources/views`, packages
(`loadViewsFrom()`), `View::addNamespace()` / `prependNamespace()` / `replaceNamespace()` and published
overrides. Resolving never makes a view trusted: whatever file the finder returns, including a `.php`
view, is compiled by the sandbox compiler.

```php
$sandbox->allowView('plugin::page');          // exactly this view
$sandbox->allowView('plugin::emails.*');      // one level: plugin::emails.welcome
$sandbox->allowView('plugin::partials.**');   // any depth
$sandbox->allowViewNamespace('plugin');       // the whole namespace
$sandbox->denyView('plugin::admin.**');       // take views back out (see "Deny rules")
```

Every view a template reaches is checked and rendered by the same sandbox: `@include`, `@includeIf`,
`@includeWhen`, `@includeUnless`, `@includeFirst`, `@each`, `@extends`, `@component`, component views
and dynamic names like `@include($name)`. View names containing `..`, `/`, `\` or NUL are rejected.

**Binding a namespace** makes it *always* sandboxed, whoever renders it: `view()`, an `@include` from a
trusted template, a Mailable or a Livewire component's `render()`.

```php
BladeSandbox::sandboxNamespace('plugin', BladeSandbox::make()
    ->allowViewNamespace('plugin')
    ->allowDto(ArticleDTO::class));

view('plugin::page', $data)->render();   // rendered by the sandbox
```

In config: `'namespaces' => ['plugin' => 'default']`. Every view engine is decorated, so all files in
the namespace's directories go to the sandbox; other views are unaffected.

**Template loaders (database, API, …).** Templates that are not files get their own namespace and work
everywhere a view name works (`renderView()`, `view()`, `@include`, `@extends`, `@each`, components,
validation, integrity manifests), mixed with file views:

```php
use SytxLabs\BladeSandbox\Templates\EloquentTemplateLoader;

BladeSandbox::loader('cms', new EloquentTemplateLoader(
    Template::class,
    name: 'identifier',
    content: 'content',                               // or fn (Template $t) => $t->translate(app()->getLocale())
    query: fn ($query) => $query->where('active', true),
));

BladeSandbox::make()->allowViewNamespace('cms')->renderView('cms::landing-page', $data);
```

- Other loaders: `new ArrayTemplateLoader(['welcome' => '…'])`, a closure
  (`BladeSandbox::loader('api', fn (string $name): ?string => …)`) or your own
  `Contracts\TemplateLoader`.
- Config: `'loaders' => ['cms' => ['driver' => 'eloquent', 'model' => Template::class, 'name' => 'identifier', 'content' => 'content']]`,
  `['driver' => 'array', 'templates' => […]]`, a loader class, or a driver
  registered with `BladeSandbox::extendLoader()`.
- Loader views must be allowed like any other view; their source is untrusted. Drafts, publishing
  and history belong to your own model: a loader (or its `query` constraint) decides which version a
  name serves.

## 4. Data: DTOs, objects, helpers, macros

### DTOs

```php
$sandbox->allowDto(ArticleDTO::class);      // this DTO (and subclasses)
$sandbox->allowDto(Dto::class);             // every subclass of your DTO base class
$sandbox->allowDtoNamespace('App\\DTO');    // every class in the namespace
```

An allowed DTO exposes its **own public API** without per-member rules:

```blade
{{ $article->name }}          {{-- public property --}}
{{ $article->getLabel() }}    {{-- public method --}}
{{ $article->label }}         {{-- zero-argument public method (getter convention of __get()) --}}
{{ $article['name'] }}        {{-- ArrayAccess, public properties only --}}
{{ $article }}                {{-- string conversion, escaped --}}
@foreach($article as $key => $value) … @endforeach
```

- Names are resolved only against public, non-static members found by reflection, never passed to
  `__get()`, `__call()` or `offsetGet()`.
- Never reachable: static methods (`fromModel()`, `fromLivewire()`), magic methods, `serialize()` /
  `unserialize()`, `toLivewire()`, `offsetSet()` / `offsetUnset()`, `setPropertiesFromArray()`.
- **Return values are checked again** — `allowDto(X)` trusts X, not what X returns: another allowed
  DTO exposes its API, Carbon / enums / Collections use the value-object defaults, anything else (e.g.
  a model) the normal object policy (default: nothing).
- `toArray()`, iteration and `{{ $dto }}` run the DTO's own code, which may call every public
  zero-argument method. `nativeDtoConversion(false)` builds them from public properties only.
- Wireable DTOs keep working with Livewire's hydration; templates cannot call the Livewire or
  serialization methods.

### Objects, functions and constants

```php
$sandbox
    ->allowFunction('asset', 'strtoupper')
    ->allowConstant('PHP_EOL')
    ->allowMethod(User::class, 'getName')            // or ['a', 'b'] / '*' (declared public methods)
    ->allowProperty(User::class, 'name')
    ->allowClassConstant(Status::class, ['Active'])  // also enum cases
    ->allowStaticMethod(Status::class, ['cases', 'from'])
    ->allowIteration(LengthAwarePaginator::class)
    ->allowArrayAccess(Fluent::class)
    ->allowStringConversion(Money::class)
    ->allowHtmlable(Markdown::class);
```

- Rules are declared per class or interface and are inherited.
- Magic methods can never be allowed; private and protected members are never reachable. Undeclared methods (calls that would reach `__call()`) need an explicit name in `allowMethod()` or `allowMacro()`; `'*'` covers declared public methods only.
- **Arguments** are checked before every call: closures, invokables, `[class, method]` arrays and callable strings for callable parameters are rejected, so `$items->filter('system')` fails even when `filter` is allowed.
- **Return values** of allowed calls are checked again: `app()->make()` stays denied even if `app` is allowed.
- **Value-object defaults** (`'value_objects' => true`) give read-only access to Carbon / `DateTimeInterface`, enum `name` / `value`, `stdClass` properties, `Stringable`, `HtmlString`,
  `ArrayObject` / `ArrayIterator`, component attribute bags and slots, and on `Collection` / `LazyCollection` / `Enumerable` to iteration plus `all count first last get has hasAny isEmpty
  isNotEmpty keys values take slice reverse chunk sortKeys sortKeysDesc forPage nth split only except`. Collection methods that take callbacks, read item members (`pluck`, `where`, …), stringify items (`implode`, `contains`) or serialize them (`toArray`, `toJson`) are excluded.

**Safe helpers.** `allowSafeHelpers()` (config `'helpers' => ['safe']`) allows vetted formatting helpers with scalar arguments only: functions such as `strtoupper`, `ucfirst`, `trim`, `substr`,
`number_format`, `round`, `date`, `now()`; `Str::limit/words/slug/title/headline/upper/lower/squish/mask/plural/…`; and `Number::format/currency/percentage/fileSize/…` where your Laravel version has `Number`. Excluded:
helpers that multiply output (`str_repeat`, `str_pad`, `sprintf`, `str_replace`, `wordwrap`, `Str::padLeft`) and helpers that stringify or compare objects (`implode`, `in_array`, `min`, `max`,
`json_encode`). Register own sets with `BladeSandbox::helpers('money', fn (Sandbox $s) => …)` and apply them with `allowHelpers('safe', 'money')`.

### Class namespaces (e.g. enums in `App\Options`)

```php
$sandbox->allowClassNamespace('App\\Options');   // 'App/Options' works too; includes sub-namespaces
```

```blade
{{ \App\Options\ArticleStatus::Running->label() }}
@foreach(App\Options\ArticleStatus::cases() as $case)
    <option value="{{ $case->value }}">{{ $case->label() }}</option>
@endforeach
```

Every class in the namespace exposes its public API: constants and enum cases, declared public instance and static methods, public properties and string conversion. `__callStatic()` is never used
(facades stay unavailable), dynamic or relative static calls (`$class::x()`, `static::x()`) are rejected, and templates have no `use` statements, so write fully-qualified names. Only allow namespaces of **safe value classes** — `App\Models` would expose `User::query()->delete()`.

### Laravel macros

```php
Collection::macro('toUpper', fn () => $this->map(fn (string $v) => strtoupper($v)));

$sandbox->allowMacro(Collection::class, 'toUpper')   // $items->toUpper()
        ->allowMacro(Str::class, ['initials']);      // Str::initials($name); no name / '*' = every macro
```

Laravel's `Macroable` and Carbon-style `hasMacro()` implementations are supported. A macro rule never
covers declared methods, and `allowStaticMethod()` never covers macros. Arguments are checked against
the macro closure's signature. Config: `'macros' => [Collection::class => ['toUpper']]`.

## 5. Template features

### Directives

The sandbox compiles templates itself, so directives registered with `Blade::directive()` or
`Blade::if()` are never executed. Unknown `@words`, such as e-mail addresses, stay literal text.

|                                     | Directives                                                                                                                                                                                                                                                                                                                                                                                                                                 |
|-------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Available by default                | `@var @if @elseif @else @unless @isset @empty @foreach @forelse @for @while @switch @case @default @break @continue @include @includeIf @includeWhen @includeUnless @includeFirst @each @extends @section @endsection @show @stop @append @overwrite @yield @parent @hasSection @sectionMissing @push @prepend @stack @once @verbatim @json @class @style @checked @selected @disabled @readonly @required @props @aware @component @slot` |
| While translations are on (default) | `@lang @choice`                                                                                                                                                                                                                                                                                                                                                                                                                            |
| Opt-in (`allowDirective()`)         | `@csrf @method @error @livewire @markdown @auth @guest @can @cannot @canany` (with their `@else…` / `@end…` variants)                                                                                                                                                                                                                                                                                                                      |
| Never available                     | `@php @inject @use @dd @dump @env @production @session @context @vite @js @entangle @this @persist @teleport @script @assets @fragment @unset @pushOnce @prependOnce @pushIf …`                                                                                                                                                                                                                                                            |

```php
$sandbox->allowDirective('csrf', 'method');
$sandbox->allowAuthDirectives();          // @auth @guest @can @cannot @canany (config 'auth_directives' => true)
$sandbox->denyDirective('include');       // switch off a default directive
```

Auth directives are evaluated through the auth guard and Gate; the user object is never exposed.
Guard names and abilities must be plain strings. Gate arguments reach your policies, which must
handle template-supplied values safely.

### Translations and routes

Translations are **on by default** for every key:

```blade
{{ __('cms.title', ['name' => $user->name]) }}   {{ trans('cms.title') }}   {{ trans_choice('cms.items', $count) }}
@lang('cms.title')   @choice('cms.items', 3)
<a href="{{ route('shop.product', $product->slug) }}">…</a>
```

```php
$sandbox->allowTranslations('cms.*', 'validation.*');   // only these keys (config 'translations' => ['cms.*'])
$sandbox->denyTranslation('cms.internal.*');            // config 'deny_translations'
$sandbox->withoutTranslations();                        // off (config 'translations' => false)

$sandbox->allowRoutes('shop.*', 'pages.show');          // route() for these names only (config 'routes')
$sandbox->denyRoute('shop.admin.*');                    // config 'deny_routes'
```

- Keys and route names are checked at runtime; literal ones also by the validator.
- `trans()` without a key (the Translator) and group keys (whole translation files) never return
  anything but a string. Replacements go through the string-conversion policy; output is escaped.
- `allowFunction('route')` still allows every route name, but `denyRoute()` wins over it too. Route
  parameters are checked like any other arguments.

### Markdown

```php
$sandbox->allowMarkdown();   // or allowDirective('markdown'); needs league/commonmark
```

```blade
@markdown($article->body)

@markdown
## Hello {{ $name }}
| Plan | Price |
|------|-------|
| Pro  | {{ $price }} |
@endmarkdown
```

GitHub-flavoured CommonMark; raw HTML is escaped and unsafe links (`javascript:`, …) are removed. The
block form converts the rendered output of the Blade inside it. The directive is only allowed in text
context (inside attribute values it is escaped). Replace the converter with
`BladeSandbox::useMarkdownConverter()` (`Contracts\MarkdownConverter`).

### Local variables: `@var`

`@var` replaces `@php($x = …)`:

```blade
@var($name = $user->name)
@var($total = 0, $label = $article->getLabel())
@foreach($items as $item) @var($total += $item->price) @endforeach

@var(
    $title = $page->title;      // separate with ";" and/or ",", across lines; comments are allowed
    $count = 0, $tax = 0.19;
)

@var
    $title = $page->title;
    $count = 0;
@endvar
```

- Only assignments to **plain local variables**: `=`, `+=`, `.=`, `??=`, `++` and destructuring such
  as `[$a, $b] = $pair`. The right-hand side goes through the normal checks; properties, offsets and
  reserved variables are rejected. Errors report the line of the failing statement.
- The block form needs `@var` alone on its line; any other `@var` without parentheses, such as
  `/** @var string */`, stays text.

### Custom sandbox directives

```php
$sandbox->directive('money', fn (int $cents, string $currency = 'EUR') => number_format($cents / 100, 2).' '.$currency);
```

```blade
@money($order->totalCents)
```

Handlers are trusted code and receive already sandboxed arguments; their result is escaped unless it
is `Htmlable`.

### Components

```php
$sandbox->allowComponent('plugin::button');   // <x-plugin::button />
$sandbox->allowComponent('plugin::forms.*');  // patterns like views
$sandbox->allowComponent('chart', fn (array $attributes) => new Chart(app(ChartService::class), $attributes['type'] ?? 'bar'));
```

- **Anonymous components** are rendered by the sandbox: `@props`, `@aware`, `$attributes` and (named)
  slots work as in Blade; `<x-dynamic-component>` is checked at runtime.
- **Class components** are never built through the container: constructor arguments come only from
  the tag attributes and parameter defaults (a service dependency needs a factory, like `chart`), and
  public component methods are not exposed to the view.
- Attribute-bag values that could break out of an attribute are rejected.

## 6. Livewire

**Rendering.** Return a sandboxed view from `render()` …

```php
class ArticleComponent extends Component implements SandboxedLivewireComponent
{
    public function sandbox(): Sandbox
    {
        return BladeSandbox::make()->allowView('plugin::page')->allowDto(ArticleDTO::class)
            ->allowLivewireDirective('click')->allowLivewireAction('save');
    }

    public function render()
    {
        return $this->sandbox()->view('plugin::page');
    }
}
```

… or keep `return view('plugin::page')` and bind the namespace. Public properties reach the view as
usual; `$__livewire`, `$_instance` and `$app` are never exposed.

**Template-side policy.** `wire:*` attributes are denied unless allowed and checked at compile time:

```php
$sandbox->allowLivewireDirective('click')      // wire:click (all modifiers)
    ->allowLivewireAction('save')              // wire:click="save", "save(1, 'x')"; '$refresh' etc. explicitly
    ->allowLivewireModel('title')              // wire:model="title", $set / $toggle
    ->allowLivewireEvent('saved')              // $dispatch('saved'), #[On('saved')]
    ->allowLivewireComponent('counter')        // <livewire:counter />, with allowDirective('livewire')
    ->allowAlpine();                           // Alpine / JavaScript surfaces
```

- An action value must be one static call with literal arguments; dynamic values, JavaScript,
  `$parent` and `$js` are rejected. `wire:model` must name an allowed static property path.
- Livewire internals such as `wire:id` and `wire:snapshot` are always denied.
- With Livewire installed, JavaScript surfaces are denied unless `allowAlpine()` is set: `x-*`,
  `@click`, `:attr`, `on*` handlers, `<script>`, `<iframe>`, `<object>`, `<embed>`, `<template>`,
  `wire:show`, `wire:text` and `wire:bind`.
- The same checks apply to attributes produced at runtime (attribute bags, `{!! !!}`).

**Server-side guard (the real boundary).** A browser can call any public method of a component. For
components implementing `SandboxedLivewireComponent` or registered with
`BladeSandbox::guardLivewireComponent(Component::class, $sandbox)`, every request is checked before
Livewire runs it:

| Request                                         | Needs                      |
|-------------------------------------------------|----------------------------|
| action `save(...)`, `$refresh`, `$commit`       | `allowLivewireAction(...)` |
| property update, `$set`, `$toggle`, file upload | `allowLivewireModel(...)`  |
| event (`$dispatch`, `#[On]`)                    | `allowLivewireEvent(...)`  |

The guard can be switched off with `'livewire' => ['guard_requests' => false]` (not recommended).
Livewire 4 single-file components contain PHP and cannot be sandboxed; use class-based components.

## 7. Deny rules

A deny **always wins**, whatever the order: over class namespaces, `'*'` rules and explicit allows,
value-object defaults, DTOs, macros, helper sets and view namespaces.

```php
$sandbox
    ->allowClassNamespace('App\\Options')
    ->denyClass(InternalFlag::class)                         // whole class, subclasses and implementors
    ->denyClassNamespace('App\\Options\\Internal')           // a sub-namespace
    ->denyMethod(ArticleStatus::class, 'secret')             // instance, static and macro calls; '*' = all
    ->denyStaticMethod(ArticleStatus::class, 'options')
    ->denyProperty(Region::class, 'internalCode')
    ->denyClassConstant(ArticleStatus::class, 'Legacy')      // constants and enum cases
    ->allowSafeHelpers()->denyFunction('date')->denyStaticMethod(Str::class, 'slug')
    ->denyConstant('PHP_OS')
    ->allowViewNamespace('cms')->denyView('cms::admin.**')   // also for component views
    ->denyRoute('admin.*')
    ->denyTranslation('internal.*')
    ->denyDirective('include');
```

A denied class exposes nothing (no members, string conversion, iteration, array access or Htmlable)
and is not treated as a DTO. Denying a class name never autoloads it. Config keys: `deny_classes`,
`deny_class_namespaces`, `deny_methods`, `deny_static_methods`, `deny_properties`,
`deny_class_constants`, `deny_functions`, `deny_constants`, `deny_views`, `deny_routes`,
`deny_translations`.

## 8. Output: sanitizers, fallback, text, cache

### Output sanitizers (XSS)

The sandbox protects the server. To keep template authors from running JavaScript in visitors'
browsers, sanitize the rendered page:

```php
$sandbox->sanitizeWith();                                   // "default": FileSanitizer if installed, else built-in
$sandbox->sanitizeWith('html');                             // built-in sanitizer (ext-dom)
$sandbox->sanitizeWith('strict', 'plugin::emails.*');       // per entry-view pattern; reject instead of clean
$sandbox->sanitizeWith(fn (string $html, string $view) => $purifier->purify($html));
$sandbox->withoutSanitizer('plugin::pages.*');              // no argument: remove all
```

- Named sanitizers (config `'sanitizers'`): `default`, `strict`, `html`, `html-strict`,
  `filesanitizer`, `filesanitizer-strict` or your own `Contracts\OutputSanitizer`. Per policy:
  `'sanitizer'` and `'view_sanitizers' => ['plugin::pages.*' => 'strict']`.
- The sanitizer runs once on the complete page (includes, layouts and components) after rendering.
- **Built-in `HtmlSanitizer`** (allowlist): drops `script`, `style`, `iframe`, `object`, `embed`,
  `template`, `svg`, `math`, forms, `base`, `link` and `meta http-equiv` with their content, unwraps
  other unknown elements, removes `on*` handlers and non-allowlisted attributes, limits URLs to
  `http(s)`, `mailto`, `tel`, relative URLs and `data:image/*` in `img[src]`; inline `style` is off by
  default. Options: `new HtmlSanitizer(extraTags: […], extraAttributes: ['*' => ['wire:key']], allowStyle: true, allowDataAttributes: false, rejectUnsafe: true)`.
- Sanitizers remove Livewire and Alpine attributes; add the ones Livewire views need via
  `extraAttributes`.

### Fallback output on errors

By default a failing render throws. With a fallback, the page shows the *unrendered* template:

```php
$sandbox->renderFallback();            // 'source': the template as it is ({{ $x }} shown as text)
$sandbox->renderFallback('strip');     // without Blade syntax (static HTML skeleton)
$sandbox->renderFallback('escaped');   // as escaped text in <pre> (editors)
$sandbox->renderFallback('empty');     // nothing
$sandbox->renderFallback(fn (string $source, string $view, Throwable $e) => '…');
$sandbox->renderFallback(false);       // throw (default)
```

- Config `'fallback' => 'source'`; custom modes via `BladeSandbox::extendFallback('notice', new MyFallback)`.
- It covers every error. The failure is still logged, dispatched as `TemplateRenderFailed` and
  reported (`'fallback_report' => false` stops reporting).
- It never evaluates anything: PHP blocks and `wire:*` attributes are removed, Alpine attributes too
  unless `allowAlpine()` is set; the sanitizer runs, and if it rejects the output, the escaped version
  is returned.

### Plain text (e-mails)

```php
$text = $sandbox->renderViewText('cms::emails.welcome', $data);   // or renderText($template, $data)
```

Paragraphs become blocks, `<br>` a line break, lists `- ` / `1. `, links `text (url)`, table cells
`a | b`; scripts and styles are dropped. Wrap lines with `'text_word_wrap' => 76`; replace the
converter via `BladeSandbox::useTextConverter()` (`Contracts\TextConverter`).

### Output cache

```php
$sandbox->cacheOutput(3600);                                      // seconds, DateInterval or DateTime
$sandbox->cacheOutput(600, 'redis', fn (string $view, array $data) => app()->getLocale());
BladeSandbox::varyOutputCacheBy(fn (Sandbox $sandbox, string $view) => tenant()->id);
```

- The key covers the policy, the view and its source, the data, the sanitizer, the user (when auth
  directives are allowed), the CSRF token (when `@csrf` is allowed) and your vary values.
- Changes to *included* views show up after the TTL expires.
- Never cached: failures, Livewire output, data that cannot be serialized, pages with `@error`
  messages.
- Config: per policy `'output_cache' => ['ttl' => 3600, 'store' => null]`; top level
  `output_cache.store` and `output_cache.prefix`.

## 9. Checking & authoring

**Validation without rendering** (before a CMS stores a template, or in CI):

```php
$result = $sandbox->validate($request->input('body'), 'body');   // string
$result = $sandbox->validateView('plugin::page');                // view + literal includes/layouts/components
$result = $sandbox->validateFile($path, 'upload.blade.php');

foreach ($result->violations() as $v) { /* $v->line, $v->capability, $v->subject, $v->message */ }
$result->throw();

$request->validate(['body' => ['required', 'string', new SandboxTemplate($sandbox)]]);   // Rules\SandboxTemplate
```

```bash
php artisan blade-sandbox:check plugin::                    # a namespace (also: directories, files, view names)
php artisan blade-sandbox:check resources/cms --policy=mail --json
```

Validation reports everything the compiler rejects (with the line) and every literally named
function, static method, constant, view, component, route or translation key that is not allowed. It
cannot see methods called on runtime values or dynamic names, so rendering still has to happen inside
the sandbox. `blade-sandbox:check` exits with code 1 on violations.

**Preview for editors.** `preview()` never throws:

```php
$result = $sandbox->preview($template, $sampleData);     // or previewView('cms::page', $sampleData)
$result->passes(); $result->html(); $result->errors();   // [['message', 'line', 'capability'], …]
return response()->json($result->toArray());
```

Static findings and the runtime error are combined. Previews use no fallback, output cache or events.

**Policy learning.** Let the sandbox tell you what a template needs:

```php
$suggestion = $sandbox->learn($template, $sampleData);    // or learnView('cms::page', $sampleData)
$suggestion = $sandbox->suggestPolicy($template);         // static only, nothing is rendered

$suggestion->toConfig();   // ['functions' => [...], 'methods' => [Article::class => ['summary']], 'views' => [...], ...]
$suggestion->toPhp();      // "$sandbox->allowFunction('strtoupper')->allowMethod(\App\Article::class, ['summary'])…"
$suggestion->flagged();    // risky candidates that are never suggested (exec(), delete(), callable arguments, …)
```

```bash
php artisan blade-sandbox:policy cms:: --policy=mail          # static suggestion for templates (--json)
```

In learning mode every denied operation is recorded and the template continues with `null`, `''` or
`[]`. **Denied operations are never executed**; denied includes are still rendered, sandboxed, so
their requirements are learned too. Suggestions of several templates can be combined with `merge()`.
Review a suggestion before you use it.

**Author lockout.** Count violations per template author:

```php
$sandbox->forAuthor($user->id)->render($template);   // also validate(), preview()
BladeSandbox::lockout()->isLocked($user->id);        // hits(), clear()
```

- Enable with `'author_lockout' => ['enabled' => true, 'max_violations' => 5, 'decay_minutes' => 60]`.
- Every security violation of a tagged sandbox counts, and so does every failing validation.
- After `max_violations` within the window the author is locked: renders throw
  `AuthorLockedException` (the fallback applies), `validate()` and the `SandboxTemplate` rule report
  the lock, and `Events\AuthorLocked` is dispatched.
- The counter uses Laravel's cache; replace it via `BladeSandbox::useViolationLimiter()`
  (`Contracts\ViolationLimiter`).

**Integrity manifest.** Render only view files that were known and unchanged when the manifest was
created:

```bash
php artisan blade-sandbox:hash plugin --output=storage/app/blade-sandbox/plugin.json   # --unsigned possible
```

```php
$sandbox->verifyIntegrity(storage_path('app/blade-sandbox/plugin.json'));   // config: 'integrity_manifest'
```

Every render of a view (including includes, layouts, components and loader views) checks its SHA-256
hash; a changed or added file throws `TemplateIntegrityException`. Manifests are signed with
HMAC-SHA256 (APP_KEY or `integrity.key`); unsigned ones are refused unless `requireSignature: false`
is set. Regenerate the manifest on every deployment.

## 10. Running renders: limits, isolation, queue, logging

**Limits.** The compiler inserts a checkpoint into every loop iteration, include, layout and
component:

```php
$sandbox->maxIterations(1_000_000)   // loop iterations per render (default)
    ->timeout(10_000)                // ms per render (default 10 s)
    ->maxMemory(64 * 1024 * 1024)    // memory growth (default off)
    ->maxOutputBytes(5_000_000)      // output size (default off)
    ->maxDepth(32);                  // nested views (default 32)
```

`@while(true)` and endless generators throw `SandboxLimitExceededException`. Config:
`'limits' => ['max_iterations', 'timeout_ms', 'max_memory_bytes', 'max_output_bytes', 'max_depth']`
(the old top-level keys still work); 0 disables a limit (except depth). Lazy collections are not counted up front, so `$loop->count` and `$loop->last`
are `null` for them. Limits are cooperative: a single call into your code is not interrupted — use
isolation for that.

**Isolated rendering** in a separate PHP process with a hard timeout and memory limit:

```php
$html = $sandbox->isolated(timeoutSeconds: 5, memoryLimit: '128M')->renderView('cms::page', $data);
```

- The child (`php artisan blade-sandbox:render`) performs only the sandboxed render. It is killed after
  the timeout (`SandboxLimitExceededException`), and running out of memory ends only the child.
- Sanitizer, fallback, output cache, events and author lockout stay in your process; violations are
  rethrown as the same exception class.
- The policy must be transferable: no closures (component factories, sandbox directives), or an
  unmodified config policy (`BladeSandbox::policy('name')->isolated()`).
- Data is passed through `serialize()`, so objects with their own `__serialize()` (DTOs, models) must
  round-trip correctly. Loaders, macros and helper sets must be registered in a service provider or
  config.
- Config: `'isolation' => ['timeout' => 10, 'memory_limit' => '256M', 'command' => null]`; the command
  defaults to `[PHP_BINARY, base_path('artisan'), 'blade-sandbox:render']` and can point to a wrapper
  (cgroups, containers). Needs symfony/process.

**Queued rendering** for expensive renders (PDFs, newsletters):

```php
$sandbox->queueRenderView('cms::newsletter', $data, then: SendNewsletter::class, context: ['campaign' => 7])
    ->onQueue('renders');                                  // a PendingDispatch

$sandbox->queueRender($template, $data,
    then: fn (string $html, array $context) => Pdf::loadHTML($html)->save(...),
    catch: fn (Throwable $e, array $context) => report($e));
```

The worker (`Jobs\RenderTemplate`, one try) rebuilds the sandbox and runs the complete pipeline:
sanitizer, fallback, events, author lockout and `isolated()` if set. `then` / `catch` are invokable
class names (resolved from the container) or closures. Sandbox, data and context must be serializable
(same rules as isolation); sanitizers and fallbacks that are closures are rejected at dispatch instead
of being dropped.

**Logging.** Security violations are logged with capability, subject and view, e.g.
`[blade-sandbox] Forbidden method App\Models\User::delete()`. Template source and runtime values are
never logged.

```php
'logging' => ['enabled' => true, 'channel' => 'security', 'level' => 'warning', 'logger' => null],   // config

BladeSandbox::useLogger(new CmsLogger(), 'notice');     // every new sandbox: PSR-3 logger, class or channel
$sandbox->logUsing('security', 'error');                // this sandbox only; $sandbox->debug() = on / off
```

`logger` is a `Psr\Log\LoggerInterface` class (resolved from the container) or a channel name. Only
channel and class names are carried over to isolated and queued renders. The legacy
`'audit' => ['enabled', 'channel']` keys still work.

## 11. Compiled template cache

Compiled templates are stored under `sha256(compiler version + Livewire major | policy fingerprint | source)`:
a template compiled under one policy is never reused under another, a changed template gets a new
file, and runtime checks still run on every render.

```php
'cache' => [
    'driver' => env('BLADE_SANDBOX_CACHE_DRIVER', 'file'),   // file | disk | memory | custom
    'path' => env('BLADE_SANDBOX_CACHE_PATH'),               // null = <view.compiled>/blade-sandbox
    'disk' => env('BLADE_SANDBOX_CACHE_DISK', 'local'),
],
```

- `file`: the directory in `path`.
- `disk`: the sub directory `path` (default `blade-sandbox`) of a **local** filesystem disk; compiled
  files are included, so S3-like disks cannot hold them.
- `memory`: a private temporary directory per process, removed at shutdown (read-only deployments,
  tests).
- Custom: `BladeSandbox::extendCache('name', fn (array $config) => …)` or a
  `Contracts\CompiledTemplateStore` class. `BladeSandbox::useCache($directoryOrStore)` switches at
  runtime. The legacy `cache_path` key is still honoured.

Clear it with `php artisan blade-sandbox:clear`. The cache grows with every template / policy
combination, and its directory must not be writable by untrusted parties.

## 12. Events, testing & extension points

| Event                              | When                                                           |
|------------------------------------|----------------------------------------------------------------|
| `Events\TemplateRendered`          | a render finished (`view`, `durationMs`, `bytes`, `fromCache`) |
| `Events\SecurityViolationDetected` | a policy violation (`view`, `violation`)                       |
| `Events\SandboxLimitExceeded`      | a limit was hit (`view`, `exception`)                          |
| `Events\TemplateRenderFailed`      | any failure (`view`, `exception`, `fallback` mode or `null`)   |
| `Events\AuthorLocked`              | an author reached the violation limit (`author`, `violations`) |

```php
use SytxLabs\BladeSandbox\Facades\BladeSandbox;

BladeSandbox::fake();                                   // records events, still renders for real
$this->get('/landing')->assertOk();
BladeSandbox::assertRendered('cms::landing-page');      // name or pattern, optional count
BladeSandbox::assertNoViolations();                     // also: assertViolation(), assertNotRendered(),
                                                        //       assertNothingRendered(), assertFallbackUsed()

// use SytxLabs\BladeSandbox\Testing\InteractsWithBladeSandbox; in a TestCase:
$this->assertSandboxRenders($sandbox, '{{ $a }}', '1', ['a' => 1]);
$this->assertSandboxDenies($sandbox, '{{ exec("id") }}', ForbiddenFunctionException::class);
$this->assertSandboxTemplateValid($sandbox, $template);   // also assertSandboxTemplateInvalid(), assertSandboxRendersView()
```

| Extension point           | How                                                                                                     |
|---------------------------|---------------------------------------------------------------------------------------------------------|
| Presets                   | `Sandbox::macro()`, config `'presets'` (applied first)                                                  |
| Policy inheritance        | `$sandbox->extend()`, config `'extends'`                                                                |
| Package overrides         | `BladeSandbox::configure()`, `definePolicy()`, `extendPolicy()`                                         |
| Logger                    | PSR-3 logger / class / channel: config `'logging'`, `BladeSandbox::useLogger()`, `$sandbox->logUsing()` |
| Policy per request        | `Contracts\PolicyResolver`, `BladeSandbox::resolvePolicyUsing()`, config `'policy_resolver'`            |
| Helper sets               | `BladeSandbox::helpers()`, `allowHelpers()`, config `'helpers'`                                         |
| Template sources          | `Contracts\TemplateLoader`, `BladeSandbox::loader()` / `extendLoader()`                                 |
| Directives                | `$sandbox->directive()`                                                                                 |
| Markdown                  | `Contracts\MarkdownConverter`, `BladeSandbox::useMarkdownConverter()`                                   |
| Output sanitizers         | `Contracts\OutputSanitizer`, `sanitizeWith()`, config `'sanitizers'`                                    |
| Fallback modes            | `Contracts\FallbackRenderer`, `BladeSandbox::extendFallback()`                                          |
| Plain text                | `Contracts\TextConverter`, `BladeSandbox::useTextConverter()`                                           |
| Output cache key          | `BladeSandbox::varyOutputCacheBy()`, `cacheOutput(key: …)`                                              |
| Author lockout            | `Contracts\ViolationLimiter`, `BladeSandbox::useViolationLimiter()`                                     |
| Compiled template storage | `Contracts\CompiledTemplateStore`, `BladeSandbox::extendCache()`                                        |
| Isolation command         | config `isolation.command`                                                                              |

## 13. Configuration reference

**Policy keys** (`policies.<name>`):

| Key                                                                                                                                                                                                             | Effect                                                                  |
|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------|
| `extends`                                                                                                                                                                                                       | parent policies (name or list)                                          |
| `presets`                                                                                                                                                                                                       | Sandbox macros applied first                                            |
| `views`, `view_namespaces`                                                                                                                                                                                      | allowed views (patterns) and namespaces                                 |
| `dtos`, `dto_namespaces`, `native_dto_conversion`                                                                                                                                                               | DTOs                                                                    |
| `methods`, `properties`, `iteration`, `array_access`, `string_conversion`                                                                                                                                       | object rules (`[Class::class => [...]]` / class lists)                  |
| `functions`, `constants`, `class_constants`, `static_methods`                                                                                                                                                   | functions and static access                                             |
| `macros`                                                                                                                                                                                                        | `[Class::class => ['name']]` or `'*'`                                   |
| `class_namespaces`                                                                                                                                                                                              | whole PHP namespaces                                                    |
| `components`, `directives`, `auth_directives`                                                                                                                                                                   | components, opt-in directives, the five auth directives                 |
| `translations`                                                                                                                                                                                                  | `true` (default, every key), `false` or key patterns                    |
| `routes`                                                                                                                                                                                                        | route name patterns for `route()`                                       |
| `deny_classes`, `deny_class_namespaces`, `deny_methods`, `deny_static_methods`, `deny_properties`, `deny_class_constants`, `deny_functions`, `deny_constants`, `deny_views`, `deny_routes`, `deny_translations` | deny rules                                                              |
| `helpers`                                                                                                                                                                                                       | helper sets, e.g. `['safe']`                                            |
| `fallback`                                                                                                                                                                                                      | `false`, `'source'`, `'strip'`, `'escaped'`, `'empty'` or a custom mode |
| `output_cache`                                                                                                                                                                                                  | `null` or `['ttl' => 3600, 'store' => null]`                            |
| `raw_echo`                                                                                                                                                                                                      | allow `{!! !!}`                                                         |
| `integrity_manifest`                                                                                                                                                                                            | path of a signed manifest                                               |
| `sanitizer`, `view_sanitizers`                                                                                                                                                                                  | output sanitizers                                                       |
| `livewire`                                                                                                                                                                                                      | `directives`, `actions`, `models`, `events`, `components`, `alpine`     |

**Top-level keys** (all optional; defaults in `SandboxConfig::defaults()`):

| Key                                                                                            | Effect                                                 |
|------------------------------------------------------------------------------------------------|--------------------------------------------------------|
| `default`                                                                                      | name of the default policy                             |
| `policy_resolver`                                                                              | `PolicyResolver` class choosing the policy per request |
| `namespaces`                                                                                   | view namespaces bound to a policy                      |
| `value_objects`                                                                                | value-object defaults on / off                         |
| `limits` (`max_depth`, `max_output_bytes`, `max_iterations`, `timeout_ms`, `max_memory_bytes`) | limits (legacy: the same keys at the top level)        |
| `cache` (`driver`, `path`, `disk`), `cache_path`                                               | compiled template cache                                |
| `loaders`                                                                                      | template loaders per namespace                         |
| `output_cache` (`store`, `prefix`)                                                             | output cache store                                     |
| `fallback_report`                                                                              | report exceptions replaced by fallback output          |
| `author_lockout` (`enabled`, `max_violations`, `decay_minutes`, `store`, `prefix`)             | author lockout                                         |
| `isolation` (`timeout`, `memory_limit`, `command`)                                             | isolated rendering                                     |
| `text_word_wrap`                                                                               | line width for `renderText()`                          |
| `hidden_variables`                                                                             | data keys never exposed (`app`, `__env`, …)            |
| `logging` (`enabled`, `channel`, `level`, `logger`)                                            | violation logging (legacy: `audit`)                    |
| `sanitizers`                                                                                   | named output sanitizers                                |
| `integrity` (`key`)                                                                            | manifest signing key (default APP_KEY)                 |
| `livewire` (`guard_requests`)                                                                  | server-side Livewire guard                             |

## 14. Exceptions

All exceptions extend `SandboxException`. Policy denials extend `SecurityViolationException`
(`capability()`, `subject()`, `templateLine()`). Messages name the denied capability and never include
template source or runtime values.

| Exception                                                    | Thrown when                                                                                                            |
|--------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| `ForbiddenFunctionException`                                 | a function (or a callable argument of one) is not allowed                                                              |
| `ForbiddenMethodException`                                   | a method, macro, magic behaviour (string conversion, ArrayAccess, iteration, JSON) or callable argument is not allowed |
| `ForbiddenPropertyException`                                 | a property, constant or class constant is not allowed                                                                  |
| `ForbiddenRouteException`                                    | a route name is not allowed                                                                                            |
| `ForbiddenTranslationException`                              | a translation key is not allowed                                                                                       |
| `ForbiddenViewException` / `ForbiddenViewNamespaceException` | a view (or its whole namespace) is not allowed, is denied or has an invalid name                                       |
| `ForbiddenComponentException`                                | a component is not allowed or needs the container                                                                      |
| `ForbiddenDirectiveException`                                | a directive is not allowed, never available or registered by the app, or raw echo is not allowed                       |
| `ForbiddenLivewireDirectiveException`                        | a `wire:*` directive or JavaScript surface is not allowed                                                              |
| `ForbiddenLivewireActionException`                           | a Livewire action, model, event or magic action is not allowed (template or request)                                   |
| `ForbiddenDtoException`                                      | a DTO conversion is called while native DTO conversion is off                                                          |
| `UnsafeOutputException`                                      | a rejecting (`strict`) sanitizer found unsafe output                                                                   |
| `TemplateIntegrityException`                                 | a view is missing from the manifest, changed, or the signature is invalid                                              |
| `InvalidSandboxTemplateException`                            | a construct can never be sandboxed (raw PHP, `eval`, `new`, closures, …), a syntax error, or an HTML context violation |
| `SandboxLimitExceededException`                              | an iteration, time, memory, output or depth limit was exceeded (also in an isolated process)                           |
| `AuthorLockedException`                                      | the template author is locked after too many violations                                                                |

## 15. Development

```bash
composer test       # PHPUnit: unit, feature, security regression, view, Livewire, compatibility
composer analyse    # PHPStan
composer fuzz       # compiler fuzzing with 5000 generated templates per seed
composer cs         # code style check (Laravel Pint with portavice/laravel-pint-config)
composer csfix      # fix the code style
```

- Code style: [portavice/laravel-pint-config](https://github.com/portavice/laravel-pint-config), as in [SytxLabs/FileSanitizer](https://github.com/SytxLabs/FileSanitizer). `pint.json` extends it, excludes
  the Blade view fixtures and one DTO fixture kept verbatim, and turns off `single_line_comment_spacing` so the foldable `//#region …` / `//#endregion …` markers in the classes stay as they are.
- `tests/Security/FuzzTest.php` generates malicious templates (fixed seeds in the normal suite, more with `BLADE_SANDBOX_FUZZ_ITERATIONS`). A canary object and a canary function prove that no forbidden
  code runs in `render()`, the fallback, `validate()`, `preview()` or `learn()`.
- 900+ tests (unit, feature, security, view, Livewire, compatibility, fuzz) cover 97%+ of lines and 91%+ of methods in `src/`; the remainder is defense-in-depth code that later checks make unreachable, or cross-process isolation internals that a coverage tool cannot see into.
- `.gitattributes` forces LF line endings. On Windows, re-checkout once if files still have CRLF: `git rm -rq --cached . && git reset --hard`.
- **Security:** report vulnerabilities privately as described in [SECURITY.md](SECURITY.md). **License:** MIT.
