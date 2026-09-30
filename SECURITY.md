# Security

The threat model of `sytxlabs/blade-sandbox`: what the sandbox guarantees, what it deliberately does
not do, and what must stay trusted. Usage is documented in the [README](README.md).

> The sandbox controls the capabilities available inside a template. It only sanitizes HTML when you
> enable an output sanitizer, and it is only process isolation when you render with `isolated()`.

## Reporting a vulnerability

Please do **not** open public issues for security problems. Report them privately via GitHub security
advisories ("Report a vulnerability" on the repository's *Security* tab) or to the SytxLabs
maintainers directly, with a minimal template, the policy you used and the Laravel, Livewire and PHP
versions. We aim to acknowledge reports within 72 hours.

## Threat model

| Actor                                                                            | Trust                                                            |
|----------------------------------------------------------------------------------|------------------------------------------------------------------|
| Application developer: PHP code, policy, service providers, config               | trusted                                                          |
| Template author: template strings, views in sandboxed namespaces, loader sources | **untrusted**                                                    |
| Template data: values passed to `render()` / `renderView()`                      | trusted to exist; what the template can do with it is restricted |
| Visitor / browser, including Livewire requests                                   | **untrusted**                                                    |

Goal: an untrusted template author can only use the capabilities the policy grants. They cannot
execute PHP, reach the container, the database, the filesystem or the environment, or leave the
sandbox through views, components or Livewire.

## Guarantees

**Compilation.** The sandbox has its own Blade compiler. Every PHP expression is parsed into an AST and
passes two stages: a per-expression allowlist and rewrite, then a closed-world validation of the whole
compiled file. Rejected structurally, not by string matching: raw PHP tags, `@php`, `eval`,
`include` / `require`, backticks, `exit`; closures, arrow functions, first-class callables; `new`,
`clone`, static properties, magic constants; variable variables and variable functions (`$f()`,
`'system'()`); `print`, `yield`, `throw`, references and the pipe operator.

**Functions and static access.**
- Only allowed functions can be called — including PHP builtins (`system`, `exec`,
  `file_get_contents`) and Laravel helpers (`app`, `config`, `request`, `env`, `view`).
- Static calls only reach **declared public static** methods allowed with `allowStaticMethod()`,
  `allowClassNamespace()` or `allowMacro()`. `__callStatic()` is never reached except for allowed
  macros; facade subclasses are never reachable.
- `$app` and `$__env` are hidden, and container objects passed as data are inert.

**Objects.** Every operation on a value is mediated at runtime: method and property access,
ArrayAccess, iteration, string conversion (echo, concatenation, interpolation, loose comparison,
`@switch`, casts, attribute bags), Htmlable rendering, JSON serialization, `(array)` casts and
destructuring.
- Magic methods can never be allowed; non-public or unknown names are never passed to `__get`,
  `__call` or `offsetGet`.
- Undeclared methods reach `__call()` only as allowed macros or explicitly named methods. `'*'` rules
  and class namespaces cover declared methods only, so a macro some package registers later, or a
  forwarding `__call()`, does not become reachable by accident.
- Return values of allowed calls are checked again.

**Callable injection.** Arguments are checked for every call, including calls to allowed functions
and methods. Closures, invokable objects, `[class, method]` arrays and callable strings for
callable-typed parameters are rejected. Named arguments are matched to their parameters. Callable
detection never autoloads classes.

**Views, components, directives.**
- Every include, layout, `@each`, component and dynamic view name is checked and rendered by the same
  sandbox. View names are validated (no `..`, `/` or NUL).
- A view file is never trusted because of where it lives; bound namespaces are sandboxed on every
  rendering path.
- Class components are built without the container, and their methods are not exposed.
- Application directives (`Blade::directive()`, `Blade::if()`) are never compiled; dangerous built-ins
  are never available. `@markdown` escapes raw HTML and drops unsafe links.

**Translations and routes.** Translations are on by default; every key is checked against the
translation policy (patterns, deny rules, off switch). The Translator object and whole translation
groups are never returned, and replacements go through the string-conversion guard. `route()` can be
limited to route-name patterns (`allowRoutes()`); denied names always win.

**Livewire.** Two separate threats:
1. **Untrusted template.** `wire:*` directives, actions, models and events are checked at compile
   time; an action value must be one static call with literal arguments. JavaScript surfaces (Alpine,
   `on*`, `<script>`, `wire:show/text/bind`) are denied unless `allowAlpine()` is set. The HTML context
   tracker rejects dynamic output that could form new tags or attributes, and branches or includes
   that leave a tag half-open.
2. **Untrusted browser.** A browser can call any public method, whatever the template contains.
   **The server-side guard is the real boundary:** for guarded components
   (`SandboxedLivewireComponent` or `guardLivewireComponent()`) every action call, magic action
   (`$set`, `$toggle`, `$refresh`, `$commit`), property update, event and upload is checked against the
   policy before Livewire runs it. Unguarded components keep Livewire's normal behaviour. Livewire 4
   single-file components embed PHP and cannot be sandboxed.

**Compiled cache.** The key contains the compiler version, the installed Livewire major version and
the policy fingerprint (deny rules included), so a template compiled under one policy is never reused
under another.

## Policy semantics

- **Default deny.** The only built-in allowances are the read-only value-object defaults (`'value_objects' => false` switches them off).
- **A deny always wins.** `deny*()` rules are checked before class namespaces, `'*'` rules, explicit allows, value-object defaults, DTOs, macros, helper sets and view namespaces. They cover classes (with subclasses and implementors), namespaces (with sub-namespaces), single members, functions, constants, views (including component views), routes, translation keys and directives.
- **No autoloading from templates.** For classes not loaded yet only the exact name and namespaces are compared; a class named in a template is never autoloaded by a policy or callable check.
- **Inheritance** (`extends` / `extend()`) unites parent rules: denies and denied directives keep winning, and restrictions (translations off, native DTO conversion off) apply if any parent sets them.
- **DTO trust boundary.** `allowDto(X)` makes objects of X and its subclasses template data objects whose *own* public, non-static API is available.
  - Never available: statics, non-public members, magic methods, `serialize` / `unserialize`, `toLivewire` / `fromLivewire`, the `offset*` methods, `setPropertiesFromArray`, `fromModel`, `fill`.
  - Names are never delegated to `__get` / `__isset` / `offsetGet` / `__call`, because DTO base classes often check names with the visibility-blind `property_exists` / `method_exists`.
  - Return values are not trusted: a model returned by a DTO falls under the normal object policy.
  - Native conversions (`{{ $dto }}`, iteration, `toArray()`) run the DTO's own code; a typical base class calls every public zero-argument method and JSON-encodes the result, which can include private properties. `nativeDtoConversion(false)` builds them from public properties only.
  - Only allow pure data carriers: `allowDto(Model::class)` would expose `delete()`.

## Operational features

- **Sanitizers.** Without one, templates may contain any HTML and JavaScript: the sandbox protects the server, not the visitors. `sanitizeWith()` runs an allowlist sanitizer on the complete page
  (sytxlabs/filesanitizer if installed, else the built-in `HtmlSanitizer`). HTML parser differentials (mXSS) cannot be ruled out completely, so add a Content-Security-Policy. The default denial of JavaScript surfaces with Livewire installed is defense in depth, not a sanitizer.
- **Fallback output** returns the *unrendered* template instead of throwing and never evaluates
  anything: PHP blocks and `wire:*` attributes are removed (Alpine attributes unless `allowAlpine()`),
  the sanitizer runs, and rejected output is returned escaped. A deliberate error publishes no more
  than the static markup a successful render would.
- **Output cache** stores the sanitized output. The key includes the user when auth directives are
  allowed and the CSRF token when `@csrf` is allowed; `@error` pages, Livewire output and failures are
  never cached. Global state your code reads (locale, tenant, time) must be added via
  `varyOutputCacheBy()` or the `key` callback.
- **Validation and integrity.** `validate*()` and `blade-sandbox:check` find everything the compiler
  rejects plus literally named capabilities that are not allowed; they cannot see runtime values, so
  templates must still be rendered inside the sandbox. `verifyIntegrity()` detects modified or added
  view files and relies on the secrecy of the signing key (APP_KEY by default).
- **Policy learning.** `learn()` records denied operations instead of failing and **never executes
  them**; the template continues with neutral values, and denied includes are still rendered,
  sandboxed. Risky candidates (process and container helpers, write methods, callable arguments,
  facades, never-available directives) are flagged, not suggested. Review suggestions before adopting
  them.
- **Policy resolver and package overrides.** Application code; a wrong choice grants the chosen policy.
  `configure()`, `definePolicy()` and `extendPolicy()` run with the same trust as config.
- **Logging.** Violations are logged with capability, subject and view only, never template source or
  runtime values; the logger (PSR-3 class, channel) is trusted code.
- **Author lockout.** `forAuthor()` counts violations per author in Laravel's cache; a locked author can
  neither render nor pass validation.
- **Isolation and queue.** `isolated()` renders in a child PHP process that is killed after a
  wall-clock timeout and has its own `memory_limit`; `queueRender()` runs the full pipeline on a worker.
  Both pass a serialized policy and data between processes of your own application — never user input.

## Trusted inputs

These must stay under the application developer's control:

- the **policy**: every `allow*()` / `deny*()` call, `config/blade-sandbox.php`, the policy resolver and
  code overrides from service providers (`configure()`, `definePolicy()`, `extendPolicy()`);
- the **template data**: a value you pass is visible through the allowed API;
- **code reached through allowances**, which runs with full application privileges: functions and
  methods, DTO methods and conversions, component classes and factories, sandbox directive handlers
  (an `Htmlable` result is inserted raw), macros, presets, helper sets, value objects, template loaders
  (they decide which source a view name maps to; the source itself is untrusted), fallback renderers,
  text and Markdown converters, sanitizers, loggers and cache stores;
- the **compile cache directory or store**: whoever can write there can execute PHP;
- the **cache store** of the output cache and author lockout: whoever can write there can change page
  output or reset / set lockout counters (but not execute PHP);
- the **queue backend**: job payloads contain serialized objects, so whoever can write there can
  inject them — keep it as trusted as your cache and database;
- the **view finder configuration** (which directories belong to which namespace);
- Laravel, Livewire and PHP themselves.

Some allowances are riskier than they look: `allowFunction('implode')` stringifies objects inside
arrays; `allowMethod(User::class, '*')` exposes `delete()`, and Eloquent attributes can lazy-load
relations; `allowClassNamespace('App\Models')` exposes `User::query()`. Allow only pure functions and
safe value classes.

## Not protected

- **Resource exhaustion, without `isolated()` only partially.** Loop iterations, render time, memory
  growth, output size and include depth are enforced at checkpoints in every loop iteration and
  include, which stops infinite loops and endless generators. Not covered: a slow or blocking call
  into application code between two checkpoints, memory beyond PHP's `memory_limit` within one
  iteration, filling the compile cache with many distinct templates, and many parallel renders.
  `isolated()` also stops slow or blocking calls and memory blow-ups, but the child still runs your
  application with the same privileges. For untrusted **internet** input, additionally use
  container / cgroup CPU and memory limits (e.g. a wrapper as `isolation.command`) and rate-limit
  template changes (author lockout).
- **Cross-site scripting** without an output sanitizer (see above).
- **Timing and side channels**, and information intentionally exposed through allowed APIs.
- **Denial by exceptions**: a template can always fail to render; use the fallback if needed.
- **Intentional Blade differences:** dynamic output that changes the HTML context is rejected; markup
  directives inside attribute values, `<title>` or `<textarea>` are escaped; view composers are not
  called for views included from sandboxed templates.

## Implementation notes (for reviewers)

| File                                                         | Role                                                                                                                                                                                                                                                                  |
|--------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `src/Compiler/Compilation.php`                               | own Blade lexer; never copies template text into PHP; expressions are re-printed from their AST                                                                                                                                                                       |
| `src/Compiler/SandboxRewriteVisitor.php`                     | stage 1: per-expression AST allowlist and rewrite to `$__sandbox->…`                                                                                                                                                                                                  |
| `src/Compiler/PhpAstValidator.php`                           | stage 2: closed-world validation of the compiled file (only runtime API calls, literals, local variables, control structures, escaped output)                                                                                                                         |
| `src/Compiler/HtmlContextTracker.php`                        | HTML context rules: attributes, raw text, comment edge cases (`<!-->`, `--!>`), branch / fragment neutrality                                                                                                                                                          |
| `src/Guards/*`, `src/Policy/*`                               | runtime checks and the immutable policy (`SecurityPolicy`, `DenyPolicy`, `KeyPatternPolicy`)                                                                                                                                                                          |
| `src/Isolation/*`, `src/Jobs/*`                              | isolated rendering (parent `IsolatedRenderer`, child `IsolatedRenderWorker`) and queued rendering; the sandbox state is unserialized with class allowlists, only template data and output sanitizers / fallbacks (type-checked) may contain application classes       |
| `src/Learning/*`                                             | learning mode (`LearningLog`, `PolicySuggestion`)                                                                                                                                                                                                                     |
| `tests/Security`, `tests/Livewire/HtmlContextBypassTest.php` | regression tests, one for every bypass found                                                                                                                                                                                                                          |
| `tests/Security/FuzzTest.php`                                | compiler fuzzing: generated malicious templates (PHP tags, magic methods, callables smuggled into allowed functions, context tricks) plus mutations, checked with canaries in render / fallback / validate / preview / learn; runs in CI with 3000 templates per seed |

The full suite (900+ tests across unit, feature, security, view, Livewire, compatibility and fuzz) covers
97%+ of lines and 91%+ of methods in `src/`. The remainder is defense-in-depth code an earlier check
already makes unreachable (e.g. the AST validator's own backstop, or a runtime guard superseded by a
compile-time one), or the isolated-rendering subprocess boundary, which a single-process coverage tool
cannot instrument.

**Bypasses found during development** (all fixed, regression tests kept):

| Finding                                                                                           | Fix                                                                                                   |
|---------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------|
| `<{{-- --}}?= $obj ?>` joined a PHP open tag across a removed comment                             | a trailing `<` is emitted through PHP; stage 2 only allows `echo` of runtime calls and literals       |
| `@switch($obj)` compared loosely → implicit `__toString()`                                        | `switchValue()` / `compare()` check objects, including ones nested in arrays                          |
| `(string)` casts in runtime helpers (`@lang($obj)`, `@each`, `@error`)                            | routed through the string conversion guard                                                            |
| Objects / Htmlables inside component attribute bags rendered via `e()`                            | bag values validated on output                                                                        |
| Bound component attributes unescaped in the bag                                                   | escaped like Blade's `sanitizeComponentAttribute()`; values with `"` (and `<` / `>` in text) rejected |
| Tags formed across directives or comments (`<@if(1)button@endif wire:click=…>`), `<!-->` comments | tracker carries a pending `<` and implements HTML comment edge cases                                  |
| Branches / includes / sections ending in a different HTML context                                 | context-neutrality rules for blocks, captures and markup directives                                   |
| Echo in tag-name or attribute-name position injecting attributes                                  | runtime `tagNameEcho()` / `attributeNameEcho()` accept plain names only                               |
| Named arguments bypassing the positional callable check                                           | arguments matched to parameters by name                                                               |
| `is_callable()` autoloading classes named in template strings                                     | callable detection without autoloading                                                                |
| `'*'` method rules / class namespaces reaching `__call()` (macros, forwarding proxies)            | undeclared methods need an explicit name or `allowMacro()`                                            |
