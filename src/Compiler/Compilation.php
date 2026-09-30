<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compiler;

use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Guards\DirectiveGuard;
use SytxLabs\BladeSandbox\Guards\LivewireGuard;
use SytxLabs\BladeSandbox\Policy\SecurityPolicy;

/**
 * One run of the sandbox Blade compiler over a single template (holds the lexer state).
 *
 * @internal
 */
final class Compilation
{
    /** Directive => whether it takes arguments: 'none', 'required' or 'optional'. */
    private const ARGUMENTS = [
        'if' => 'required', 'elseif' => 'required', 'else' => 'none', 'endif' => 'none',
        'unless' => 'required', 'endunless' => 'none', 'isset' => 'required', 'endisset' => 'none',
        'empty' => 'optional', 'endempty' => 'none', 'foreach' => 'required', 'endforeach' => 'none',
        'forelse' => 'required', 'endforelse' => 'none', 'for' => 'required', 'endfor' => 'none',
        'while' => 'required', 'endwhile' => 'none', 'switch' => 'required', 'case' => 'required',
        'default' => 'none', 'break' => 'optional', 'continue' => 'optional', 'endswitch' => 'none',
        'include' => 'required', 'includeif' => 'required', 'includewhen' => 'required',
        'includeunless' => 'required', 'includefirst' => 'required', 'each' => 'required',
        'extends' => 'required', 'section' => 'required', 'endsection' => 'none', 'show' => 'none',
        'stop' => 'none', 'append' => 'none', 'overwrite' => 'none', 'yield' => 'required', 'parent' => 'none',
        'hassection' => 'required', 'sectionmissing' => 'required', 'push' => 'required', 'endpush' => 'none',
        'prepend' => 'required', 'endprepend' => 'none', 'stack' => 'required', 'once' => 'none', 'endonce' => 'none',
        'verbatim' => 'none', 'endverbatim' => 'none', 'json' => 'required', 'class' => 'required', 'style' => 'required',
        'checked' => 'required', 'selected' => 'required', 'disabled' => 'required', 'readonly' => 'required',
        'required' => 'required', 'props' => 'required', 'aware' => 'required', 'component' => 'required',
        'endcomponent' => 'none', 'slot' => 'required', 'endslot' => 'none', 'csrf' => 'none', 'method' => 'required',
        'lang' => 'required', 'choice' => 'required', 'error' => 'required', 'enderror' => 'none', 'livewire' => 'required',
        'var' => 'required', 'markdown' => 'optional', 'endmarkdown' => 'none',
        'auth' => 'optional', 'elseauth' => 'optional', 'endauth' => 'none',
        'guest' => 'optional', 'elseguest' => 'optional', 'endguest' => 'none',
        'can' => 'required', 'elsecan' => 'required', 'endcan' => 'none',
        'cannot' => 'required', 'elsecannot' => 'required', 'endcannot' => 'none',
        'canany' => 'required', 'elsecanany' => 'required', 'endcanany' => 'none',
    ];

    /** Directives whose output is markup: only allowed in plain text context. */
    private const MARKUP_OUTPUT = [
        'include', 'includeif', 'includewhen', 'includeunless', 'includefirst', 'each', 'yield', 'show', 'parent',
        'stack', 'csrf', 'method', 'livewire', 'endcomponent',
    ];

    /** Directives whose output is escaped text: allowed in text, attribute values, comments and raw text. */
    private const ESCAPED_OUTPUT = ['json', 'lang', 'choice'];

    /** Directives producing attributes: allowed in text and between attributes. */
    private const ATTRIBUTE_OUTPUT = ['class', 'style'];

    /** Directives producing a bare word: allowed in text, between attributes and in attribute values. */
    private const WORD_OUTPUT = ['checked', 'selected', 'disabled', 'readonly', 'required'];

    /** Directives that capture output into a fragment (sections, stacks, slots): boundaries must be in text context. */
    private const CAPTURE = [
        'section', 'endsection', 'stop', 'append', 'overwrite', 'push', 'endpush', 'prepend', 'endprepend',
        'component', 'slot', 'endslot', 'extends', 'props', 'aware',
    ];

    /** Control flow: the HTML context must be identical at every branch boundary. */
    private const BLOCK_OPEN = ['if', 'unless', 'isset', 'foreach', 'forelse', 'for', 'while', 'switch', 'once', 'hassection', 'sectionmissing', 'error',
        'auth', 'guest', 'can', 'cannot', 'canany'];

    private const BLOCK_MIDDLE = ['elseif', 'else', 'case', 'default', 'break', 'continue',
        'elseauth', 'elseguest', 'elsecan', 'elsecannot', 'elsecanany'];

    private const BLOCK_CLOSE = ['endif', 'endunless', 'endisset', 'endempty', 'endforeach', 'endforelse', 'endfor', 'endwhile', 'endswitch', 'endonce', 'enderror',
        'endauth', 'endguest', 'endcan', 'endcannot', 'endcanany'];

    private const SPECIAL = '/\{\{--|@\{\{|@\{!!|\{\{\{|\{\{|\{!!|@|<\/?\s*x[-:]|<\/?livewire:|<\?/';

    private int $position = 0;

    private readonly int $length;

    private string $output = '';

    private string $footer = '';

    private HtmlContextTracker $html;

    /** @var list<array{id: int, emptied: bool}> */
    private array $forelse = [];

    private int $counter = 0;

    private bool $awaitingFirstCase = false;

    /** @var list<string> */
    private array $components = [];

    /** @var list<array{directive: string, state: string}> */
    private array $blocks = [];

    /** Set by checkContext(): the markup output of the current directive must be escaped. */
    private bool $escapeMarkup = false;

    private int $slots = 0;

    /**
     * @param list<string> $applicationDirectives
     */
    public function __construct(
        private readonly string $source,
        private readonly string $template,
        private readonly SecurityPolicy $policy,
        private readonly ExpressionSandboxer $expressions,
        private readonly DirectiveGuard $directives,
        private readonly LivewireGuard $livewire,
        private readonly array $applicationDirectives,
        private readonly ?TemplateReferences $references = null,
    ) {
        $this->length = strlen($source);
        $this->html = new HtmlContextTracker(
            function (string $name, ?string $value, bool $dynamic, int $line): void {
                try {
                    $this->livewire->checkAttribute($name, $value === null ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5), $dynamic);
                } catch (SecurityViolationException $violation) {
                    throw $violation->atLine($line);
                }
            },
            function (string $tag, bool $dynamic, int $line): void {
                if ($dynamic && $this->livewire->enforcesJavaScript()) {
                    throw InvalidSandboxTemplateException::forbiddenConstruct('dynamic tag names', $line);
                }
                try {
                    $this->livewire->checkTag($tag);
                } catch (SecurityViolationException $violation) {
                    throw $violation->atLine($line);
                }
            },
        );
    }

    public function run(): string
    {
        try {
            if (preg_match('/<\?(?:php|=)/i', $this->source) === 1) {
                /** @noinspection CaseInsensitiveStringFunctionsMissUseInspection */
                throw InvalidSandboxTemplateException::forbiddenConstruct('raw PHP tags', $this->lineAt((int) stripos($this->source, '<?')));
            }
            while ($this->position < $this->length) {
                if (preg_match(self::SPECIAL, $this->source, $match, PREG_OFFSET_CAPTURE, $this->position) !== 1) {
                    $this->text(substr($this->source, $this->position));
                    break;
                }
                $token = (string) $match[0][0];
                $offset = (int) $match[0][1];
                if ($offset > $this->position) {
                    $this->text(substr($this->source, $this->position, $offset - $this->position));
                }
                $this->position = $offset;
                match (true) {
                    $token === '{{--' => $this->comment(),
                    $token === '@{{' || $token === '@{!!' => $this->escapedEcho(substr($token, 1)),
                    $token === '{{{' => $this->echo(PhpBoundaryScanner::TRIPLE_ECHO, 3, 3),
                    $token === '{{' => $this->echo(PhpBoundaryScanner::ECHO, 2, 2),
                    $token === '{!!' => $this->echo(PhpBoundaryScanner::RAW_ECHO, 3, 3),
                    $token === '@' => $this->directive(),
                    $token === '<?' => $this->shortOpenTag(),
                    default => $this->tag(),
                };
            }
            $line = $this->lineAt($this->length);
            $this->html->finish();
            if ($this->blocks !== []) {
                throw InvalidSandboxTemplateException::syntax('unclosed @'.end($this->blocks)['directive'], $line);
            }
            if ($this->components !== []) {
                throw InvalidSandboxTemplateException::syntax('unclosed component <x-'.end($this->components).'>', $line);
            }
            if ($this->forelse !== []) {
                throw InvalidSandboxTemplateException::syntax('unclosed @forelse', $line);
            }
            if ($this->awaitingFirstCase) {
                throw InvalidSandboxTemplateException::syntax('@switch without @case', $line);
            }
            return $this->output.$this->footer;
        } catch (SecurityViolationException $violation) {
            throw $violation->atLine($this->line());
        }
    }

    //#region Text, comments, echoes

    private function text(string $text): void
    {
        if ($text === '') {
            return;
        }
        if ($this->awaitingFirstCase) {
            if (trim($text) !== '') {
                throw InvalidSandboxTemplateException::syntax('only whitespace is allowed between @switch and the first @case', $this->line());
            }
            $this->output .= $text;

            return;
        }
        $this->html->feed($text, $this->line());
        $this->output .= str_ends_with($text, '<') ? (substr($text, 0, -1)."<?php echo '<'; ?>") : $text;
    }

    private function comment(): void
    {
        $end = strpos($this->source, '--}}', $this->position + 4);
        if ($end === false) {
            throw InvalidSandboxTemplateException::syntax('unclosed Blade comment', $this->line());
        }
        $this->position = $end + 4;
    }

    private function escapedEcho(string $opening): void
    {
        $this->position += strlen($opening) + 1;
        $this->text($opening);
    }

    private function shortOpenTag(): void
    {
        $this->assertNotInSwitchHead();
        $this->html->feed('<?', $this->line());
        $this->output .= "<?php echo '<?'; ?>";
        $this->position += 2;
    }

    private function echo(string $mode, int $open, int $close): void
    {
        $this->assertNotInSwitchHead();
        $line = $this->line();
        $start = $this->position + $open;
        $end = PhpBoundaryScanner::find($this->source, $start, $mode);
        if ($end === null) {
            throw InvalidSandboxTemplateException::syntax('unterminated echo', $line);
        }

        $expression = substr($this->source, $start, $end - $start);
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $context = $this->html->dynamic('echo');
        $code = $this->expressions->expression($expression, $line);

        if ($mode === PhpBoundaryScanner::RAW_ECHO) {
            if (!$this->policy->allowsRawEcho()) {
                throw $this->directives->denyRawEcho();
            }
            if ($context !== 'text') {
                throw InvalidSandboxTemplateException::syntax('raw echo {!! !!} is only allowed in text context, not inside tags, attributes, comments or raw text elements', $line);
            }
            $method = 'raw';
        } else {
            $method = match ($context) {
                'attribute' => 'attributeEcho',
                'tag-name' => 'tagNameEcho',
                'attribute-name' => 'attributeNameEcho',
                'value' => 'escapeText',
                default => 'escape',
            };
        }

        $this->output .= '<?php echo $__sandbox->'.$method.'('.$code.'); ?>';
        $this->position = $end + $close;
        $this->preserveNewline();
    }

    /** PHP swallows the newline directly after "?>"; Blade doubles it for echoes so it survives. */
    private function preserveNewline(): void
    {
        if (($this->source[$this->position] ?? '') === "\n") {
            $this->output .= "\n";
        } elseif (substr($this->source, $this->position, 2) === "\r\n") {
            $this->output .= "\r\n";
        }
    }
    //#endregion Text, comments, echoes

    //#region Directives

    private function directive(): void
    {
        $previous = $this->position > 0 ? $this->source[$this->position - 1] : '';
        if ($previous !== '' && preg_match('/\w/', $previous) === 1) {
            $this->literal('@');
            return;
        }

        if (($this->source[$this->position + 1] ?? '') === '@') {
            $this->position += 2;
            $this->text('@');
            if (preg_match('/\G\w+/', $this->source, $name, 0, $this->position) === 1) {
                $this->literal($name[0]);
            }
            return;
        }

        if (preg_match('/\G\w+/', $this->source, $match, 0, $this->position + 1) !== 1) {
            $this->literal('@');
            return;
        }

        $name = $match[0];
        $lower = strtolower($name);
        $line = $this->line();

        if (!$this->directives->check($name, $this->applicationDirectives)) {
            $this->literal('@'.$name);
            return;
        }

        $after = $this->position + 1 + strlen($name);
        $arguments = null;
        $expects = self::ARGUMENTS[$lower] ?? 'optional';

        if ($expects !== 'none' && preg_match('/\G[ \t]*\(/', $this->source, $paren, 0, $after) === 1) {
            $start = $after + strlen($paren[0]);
            $end = PhpBoundaryScanner::find($this->source, $start, PhpBoundaryScanner::PARENTHESES);
            if ($end === null) {
                throw InvalidSandboxTemplateException::syntax('unbalanced parentheses in @'.$name, $line);
            }
            $arguments = substr($this->source, $start, $end - $start);
            $after = $end + 1;
        }

        if ($lower === 'var' && $arguments === null) {
            if (preg_match('/\G[ \t]*\r?\n/', $this->source, $newline, 0, $after) === 1 && preg_match('/(?<![\w@])@endvar\b/i', $this->source, $close, PREG_OFFSET_CAPTURE, $after) === 1) {
                $end = $close[0][1];
                $block = substr($this->source, $after, $end - $after);
                $this->position = $end + strlen('@endvar');
                $this->checkContext('var', $block, $line);
                $this->output .= $this->compileDirective('var', $block, $line);
                return;
            }
            $this->literal('@'.$name);
            return;
        }

        if ($expects === 'required' && $arguments === null) {
            throw InvalidSandboxTemplateException::syntax('@'.$name.' requires arguments', $line);
        }
        if ($this->awaitingFirstCase && !in_array($lower, ['case', 'default'], true)) {
            throw InvalidSandboxTemplateException::syntax('only @case or @default may follow @switch', $line);
        }

        $this->position = $after;

        if ($lower === 'verbatim') {
            $this->verbatim($line);
            return;
        }

        $this->checkContext($lower, $arguments, $line);
        $this->collectViews($lower, $arguments, $line);

        $this->output .= $this->compileDirective($lower, $arguments, $line);
    }

    /**
     * Enforces the HTML context rules of a directive (see the constants above).
     */
    private function checkContext(string $name, ?string $arguments, int $line): void
    {
        $custom = $this->policy->directives()->isCustom($name);

        $markdownBlock = ($name === 'markdown' && $arguments === null) || $name === 'endmarkdown';
        if ($name === 'show' || $name === 'endcomponent' || $markdownBlock || in_array($name, self::CAPTURE, true)) {
            $this->requireText('@'.$name, $line);
        }

        $this->escapeMarkup = false;
        if ($custom || ($name === 'markdown' && !$markdownBlock) || in_array($name, self::MARKUP_OUTPUT, true)) {
            $context = $this->html->dynamic('@'.$name);
            if ($context === 'value') {
                $this->escapeMarkup = true;
            } elseif ($context !== 'text') {
                throw InvalidSandboxTemplateException::syntax('@'.$name.' is only allowed in text context, not inside tags', $line);
            }
        } elseif (in_array($name, self::ESCAPED_OUTPUT, true)) {
            if (!in_array($this->html->dynamic('@'.$name), ['text', 'value'], true)) {
                throw InvalidSandboxTemplateException::syntax('@'.$name.' is not allowed inside tags', $line);
            }
        } elseif (in_array($name, self::ATTRIBUTE_OUTPUT, true)) {
            if (!in_array($this->html->dynamic('@'.$name), ['text', 'attribute'], true)) {
                throw InvalidSandboxTemplateException::syntax('@'.$name.' is only allowed between attributes', $line);
            }
        } elseif (in_array($name, self::WORD_OUTPUT, true)) {
            if (!in_array($this->html->dynamic('@'.$name), ['text', 'attribute', 'value'], true)) {
                throw InvalidSandboxTemplateException::syntax('@'.$name.' is not allowed inside tag or attribute names', $line);
            }
        }

        $opensBlock = in_array($name, self::BLOCK_OPEN, true) || ($name === 'empty' && $arguments !== null);
        $closesBlock = in_array($name, self::BLOCK_CLOSE, true);
        $middle = in_array($name, self::BLOCK_MIDDLE, true) || ($name === 'empty' && $arguments === null);
        if ($opensBlock) {
            $this->blocks[] = ['directive' => $name, 'state' => $this->html->blockState()];
            return;
        }
        if (!$middle && !$closesBlock) {
            return;
        }

        $block = $this->blocks[count($this->blocks) - 1] ?? null;
        if ($block === null) {
            if (in_array($name, ['break', 'continue'], true)) {
                return;
            }
            throw InvalidSandboxTemplateException::syntax('@'.$name.' without an opening directive', $line);
        }

        if ($block['state'] !== $this->html->blockState()) {
            throw InvalidSandboxTemplateException::syntax('@'.$name.' is in a different HTML context than @'.$block['directive'].' (branches must not open or close tags, attributes or comments partially)', $line);
        }

        if ($closesBlock) {
            array_pop($this->blocks);
        }
    }

    private function requireText(string $what, int $line): void
    {
        if (!$this->html->inText()) {
            throw InvalidSandboxTemplateException::syntax($what.' is only allowed in text context, not inside tags, attributes, comments or raw text elements', $line);
        }
    }

    private function collectViews(string $name, ?string $arguments, int $line): void
    {
        if ($this->references === null || $arguments === null) {
            return;
        }
        $position = match ($name) {
            'include', 'includeif', 'includefirst', 'each', 'extends', 'component', 'livewire' => 0,
            'includewhen', 'includeunless' => 1,
            default => null,
        };
        if ($position === null) {
            return;
        }

        $values = $this->expressions->arguments($arguments, $line);
        $candidates = $name === 'includefirst' ? self::literalList($values[0] ?? '') : [self::literalString($values[$position] ?? '')];
        if ($name === 'each' && ($empty = self::literalString($values[3] ?? '')) !== null && !str_starts_with($empty, 'raw|')) {
            $candidates[] = $empty;
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== null) {
                $this->references->add($name === 'livewire' ? TemplateReferences::LIVEWIRE_COMPONENT : TemplateReferences::VIEW, $candidate, $line);
            }
        }
    }

    private static function literalString(string $code): ?string
    {
        return preg_match('/\A\'((?:[^\'\\\\]|\\\\.)*)\'\z/s', $code, $match) === 1 ? stripslashes($match[1]) : null;
    }

    /** @return list<string|null> */
    private static function literalList(string $code): array
    {
        if (preg_match('/\A\[(.*)\]\z/s', $code, $match) !== 1) {
            return [];
        }

        return array_map(static fn (string $item): ?string => self::literalString(trim($item)), explode(',', $match[1]));
    }

    private function literal(string $text): void
    {
        $this->position += strlen($text);
        $this->text($text);
    }

    private function verbatim(int $line): void
    {
        $end = stripos($this->source, '@endverbatim', $this->position);
        if ($end === false) {
            throw InvalidSandboxTemplateException::syntax('unclosed @verbatim', $line);
        }
        $content = substr($this->source, $this->position, $end - $this->position);
        $this->position = $end + strlen('@endverbatim');

        foreach (preg_split('/(<\?)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if ($part === '<?') {
                $this->html->feed('<?', $line);
                $this->output .= "<?php echo '<?'; ?>";
            } else {
                $this->text($part);
            }
        }
    }

    private function compileDirective(string $name, ?string $arguments, int $line): string
    {
        $expression = fn (): string => $this->expressions->expression((string) $arguments, $line);
        $args = fn (): array => $this->expressions->arguments((string) $arguments, $line);

        switch ($name) {
            case 'if': return self::php('if ('.$expression().'):');
            case 'elseif': return self::php('elseif ('.$expression().'):');
            case 'else': return self::php('else:');
            case 'endif':
            case 'endunless':
            case 'endisset':
            case 'endempty':
            case 'endonce':
            case 'enderror':
            case 'endauth':
            case 'endguest':
            case 'endcan':
            case 'endcannot':
            case 'endcanany':
                return self::php('endif;');
            case 'unless': return self::php('if (! ('.$expression().')):');
            case 'isset': return self::php('if ('.$this->expressions->expression('isset('.$arguments.')', $line).'):');
            case 'empty':
                return $arguments !== null ? self::php('if ('.$this->expressions->expression('empty('.$arguments.')', $line).'):') : $this->forelseEmpty($line);
            case 'foreach': return self::php($this->loopStart((string) $arguments, $line));
            case 'endforeach': return self::php('endforeach; $__sandbox->popLoop(); $loop = $__sandbox->currentLoop();');
            case 'forelse':
                $id = ++$this->counter;
                $this->forelse[] = ['id' => $id, 'emptied' => false];
                return self::php('$__sb_empty_'.$id.' = true; '.$this->loopStart((string) $arguments, $line).' $__sb_empty_'.$id.' = false;');
            case 'endforelse':
                $loop = array_pop($this->forelse);
                if ($loop === null || !$loop['emptied']) {
                    throw InvalidSandboxTemplateException::syntax('@endforelse without matching @forelse/@empty', $line);
                }
                return self::php('endif;');
            case 'for': return self::php('for ('.$this->expressions->forHead((string) $arguments, $line).'): $__sandbox->tick();');
            case 'endfor': return self::php('endfor;');
            case 'while': return self::php('while ('.$expression().'): $__sandbox->tick();');
            case 'endwhile': return self::php('endwhile;');
            case 'switch':
                $this->awaitingFirstCase = true;
                return '<?php switch ($__sandbox->switchValue('.$expression().')):';
            case 'case':
                if ($this->awaitingFirstCase) {
                    $this->awaitingFirstCase = false;
                    return ' case ($__sandbox->switchValue('.$expression().')): ?>';
                }
                return self::php('case ($__sandbox->switchValue('.$expression().')):');
            case 'default':
                if ($this->awaitingFirstCase) {
                    $this->awaitingFirstCase = false;
                    return ' default: ?>';
                }
                return self::php('default:');
            case 'endswitch': return self::php('endswitch;');
            case 'break':
            case 'continue':
                if ($arguments === null || trim($arguments) === '') {
                    return self::php($name.';');
                }
                if (preg_match('/\A\s*(\d+)\s*\z/', $arguments, $level) === 1) {
                    return self::php($name.' '.max(1, (int) $level[1]).';');
                }
                return self::php('if ('.$expression().') '.$name.';');
            case 'include': return $this->echoCall('include', $this->withScope($args(), 1));
            case 'includeif': return $this->echoCall('includeIf', $this->withScope($args(), 1));
            case 'includewhen': return $this->echoCall('includeWhen', $this->withScope($args(), 2));
            case 'includeunless': return $this->echoCall('includeUnless', $this->withScope($args(), 2));
            case 'includefirst': return $this->echoCall('includeFirst', $this->withScope($args(), 1));
            case 'each': return $this->echoCall('each', $args());
            case 'extends':
                if ($this->footer !== '') {
                    throw InvalidSandboxTemplateException::syntax('multiple @extends', $line);
                }
                $this->footer = self::php('echo $__sandbox->renderLayout('.implode(', ', $this->withScope($args(), 1)).');');
                return '';
            case 'section': return self::php('$__sandbox->startSection('.implode(', ', $args()).');');
            case 'endsection':
            case 'stop':
                return self::php('$__sandbox->stopSection();');
            case 'show': return $this->echoCall('yieldSection', []);
            case 'append': return self::php('$__sandbox->appendSection();');
            case 'overwrite': return self::php('$__sandbox->stopSection(true);');
            case 'yield': return $this->echoCall('yieldContent', $args());
            case 'parent': return $this->echoCall('parentPlaceholder', []);
            case 'hassection': return self::php('if ($__sandbox->hasSection('.implode(', ', $args()).')):');
            case 'sectionmissing': return self::php('if (! $__sandbox->hasSection('.implode(', ', $args()).')):');
            case 'push': return self::php('$__sandbox->startPush('.implode(', ', $args()).');');
            case 'endpush': return self::php('$__sandbox->stopPush();');
            case 'prepend': return self::php('$__sandbox->startPrepend('.implode(', ', $args()).');');
            case 'endprepend': return self::php('$__sandbox->stopPrepend();');
            case 'stack': return $this->echoCall('yieldPushContent', $args());
            case 'once': return self::php('if ($__sandbox->once('.var_export(hash('xxh128', $this->template.'#'.$this->position), true).')):');
            case 'endverbatim': throw InvalidSandboxTemplateException::syntax('@endverbatim without @verbatim', $line);
            case 'json': return $this->echoCall('json', $args());
            case 'class': return 'class="'.$this->echoCall('classes', $args()).'"';
            case 'style': return 'style="'.$this->echoCall('styles', $args()).'"';
            case 'checked':
            case 'selected':
            case 'disabled':
            case 'readonly':
            case 'required':
                return self::php('if ('.$expression().'): echo '.var_export($name, true).'; endif;');
            case 'props': return self::php('extract($__sandbox->props('.implode(', ', $args()).', get_defined_vars()));');
            case 'aware': return self::php('extract($__sandbox->aware('.implode(', ', $args()).', get_defined_vars()));');
            case 'component':
                $this->components[] = '@component';
                return self::php('$__sandbox->startViewComponent('.implode(', ', $args()).');');
            case 'endcomponent':
                if (array_pop($this->components) !== '@component') {
                    throw InvalidSandboxTemplateException::syntax('@endcomponent without @component', $line);
                }
                return $this->echoCall('renderComponent', []);
            case 'slot':
                $slot = $args();
                if (count($slot) >= 2) {
                    return self::php('$__sandbox->startSlot('.$slot[0].', []); echo $__sandbox->escape('.$slot[1].'); $__sandbox->endSlot();');
                }
                $this->slots++;
                return self::php('$__sandbox->startSlot('.implode(', ', $slot).', []);');
            case 'endslot':
                $this->slots--;
                return self::php('$__sandbox->endSlot();');
            case 'csrf': return $this->echoCall('csrfField', []);
            case 'method': return $this->echoCall('methodField', $args());
            case 'markdown': return $arguments === null ? self::php('$__sandbox->startMarkdown();') : $this->echoCall('markdown', $args());
            case 'endmarkdown': return self::php('echo $__sandbox->stopMarkdown();');
            case 'lang':
            case 'choice':
                if (preg_match('/\A\s*([\'"])((?:(?!\1)[^\\\\])*)\1\s*(?:,|\z)/', (string) $arguments, $key) === 1) {
                    $this->references?->add(TemplateReferences::TRANSLATION, $key[2], $line);
                }
                return $this->echoCall($name, $args());
            case 'error': return self::php('if (($message = $__sandbox->errorMessage(get_defined_vars(), '.implode(', ', $args()).')) !== null):');
            case 'livewire': return $this->echoCall('livewire', $args());
            case 'auth': return self::php('if ($__sandbox->authCheck('.implode(', ', $arguments === null ? [] : $args()).')):');
            case 'elseauth': return self::php('elseif ($__sandbox->authCheck('.implode(', ', $arguments === null ? [] : $args()).')):');
            case 'guest': return self::php('if (! $__sandbox->authCheck('.implode(', ', $arguments === null ? [] : $args()).')):');
            case 'elseguest': return self::php('elseif (! $__sandbox->authCheck('.implode(', ', $arguments === null ? [] : $args()).')):');
            case 'can': return self::php('if ($__sandbox->can('.implode(', ', $args()).')):');
            case 'elsecan': return self::php('elseif ($__sandbox->can('.implode(', ', $args()).')):');
            case 'cannot': return self::php('if (! $__sandbox->can('.implode(', ', $args()).')):');
            case 'elsecannot': return self::php('elseif (! $__sandbox->can('.implode(', ', $args()).')):');
            case 'canany': return self::php('if ($__sandbox->canAny('.implode(', ', $args()).')):');
            case 'elsecanany': return self::php('elseif ($__sandbox->canAny('.implode(', ', $args()).')):');
            case 'var': return self::php(implode('; ', $this->expressions->assignments((string) $arguments, $line)).';');
        }

        if ($this->policy->directives()->isCustom($name)) {
            return $this->echoCall('directive', [var_export($name, true), '['.implode(', ', $arguments === null ? [] : $args()).']']);
        }
        throw InvalidSandboxTemplateException::syntax('unsupported directive @'.$name, $line);
    }

    private function loopStart(string $head, int $line): string
    {
        $loop = $this->expressions->foreachHead($head, $line);
        return '$__sb_loop = $__sandbox->addLoop('.$loop['subject'].'); foreach ($__sb_loop as '.(($loop['key'] !== null ? $loop['key'].' => ' : '').$loop['value']).'): $__sandbox->incrementLoop(); $loop = $__sandbox->currentLoop();';
    }

    private function forelseEmpty(int $line): string
    {
        $index = count($this->forelse) - 1;
        if ($index < 0 || $this->forelse[$index]['emptied']) {
            throw InvalidSandboxTemplateException::syntax('@empty without @forelse', $line);
        }
        $this->forelse[$index]['emptied'] = true;
        return self::php('endforeach; $__sandbox->popLoop(); $loop = $__sandbox->currentLoop(); if ($__sb_empty_'.$this->forelse[$index]['id'].'):');
    }

    /**
     * Inserts the current scope (get_defined_vars()) as argument at the given position, like Blade does for @include (variables of the including template are visible in the included one).
     *
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private function withScope(array $arguments, int $position): array
    {
        array_splice($arguments, min($position, count($arguments)), 0, ['get_defined_vars()']);
        return $arguments;
    }

    /** @param list<string> $arguments */
    private function echoCall(string $method, array $arguments): string
    {
        $call = '$__sandbox->'.$method.'('.implode(', ', $arguments).')';
        return self::php('echo '.($this->escapeMarkup ? '$__sandbox->escapeMarkup('.$call.')' : $call).';');
    }

    private static function php(string $code): string
    {
        return '<?php '.$code.' ?>';
    }

    private function assertNotInSwitchHead(): void
    {
        if ($this->awaitingFirstCase) {
            throw InvalidSandboxTemplateException::syntax('only @case or @default may follow @switch', $this->line());
        }
    }
    //#endregion Directives

    //#region Component tags

    private function tag(): void
    {
        $this->assertNotInSwitchHead();
        $line = $this->line();

        if (preg_match('/\G<\/?\s*(?:x[-:]|livewire:)/', $this->source, $component, 0, $this->position) === 1) {
            $this->requireText('component tag', $line);
        }

        if (preg_match('/\G<\/\s*x[-:]slot(?::[\w\-]+)?\s*>/', $this->source, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);
            if ($this->slots <= 0) {
                throw InvalidSandboxTemplateException::syntax('</x-slot> without <x-slot>', $line);
            }
            $this->slots--;
            $this->output .= self::php('$__sandbox->endSlot();');

            return;
        }

        if (preg_match('/\G<\/\s*x[-:]([\w\-:.]+)\s*>/', $this->source, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);
            $open = array_pop($this->components);
            if ($open !== $match[1]) {
                throw InvalidSandboxTemplateException::syntax('closing </x-'.$match[1].'> does not match the open component', $line);
            }
            $this->html->dynamic('component');
            $this->output .= self::php('echo $__sandbox->renderComponent();');

            return;
        }

        if (preg_match('/\G<\s*x[-:]slot(?::([\w\-]+))?(?=[\s>\/])/', $this->source, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);
            [$attributes, $selfClosing] = $this->attributes($line, false);
            $name = ($match[1] ?? '') !== '' ? var_export($match[1], true) : $this->takeSlotName($attributes, $line);
            $this->output .= self::php('$__sandbox->startSlot('.$name.', '.$this->attributeArray($attributes, $line).', '.$this->boundKeys($attributes).');');
            if ($selfClosing) {
                $this->output .= self::php('$__sandbox->endSlot();');
            } else {
                $this->slots++;
            }

            return;
        }

        if (preg_match('/\G<\s*x[-:]([\w\-:.]+)/', $this->source, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);
            $component = $match[1];
            [$attributes, $selfClosing] = $this->attributes($line, true);

            if ($component === 'dynamic-component') {
                $name = $this->takeAttribute($attributes, 'component', $line);
            } else {
                $name = var_export($component, true);
                $this->references?->add(TemplateReferences::COMPONENT, $component, $line);
            }

            $this->html->dynamic('component');
            $this->output .= self::php('$__sandbox->startComponent('.$name.', '.$this->attributeArray($attributes, $line).', '.$this->boundKeys($attributes).');');
            if ($selfClosing) {
                $this->output .= self::php('echo $__sandbox->renderComponent();');
            } else {
                $this->components[] = $component;
            }

            return;
        }

        if (preg_match('/\G<\s*livewire:([\w\-:.]+)/', $this->source, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);
            [$attributes, $selfClosing] = $this->attributes($line, true);
            if (!$selfClosing) {
                throw InvalidSandboxTemplateException::syntax('only self-closing <livewire:...> tags are supported', $line);
            }
            $this->html->dynamic('component');
            $this->references?->add(TemplateReferences::LIVEWIRE_COMPONENT, $match[1], $line);
            $this->output .= self::php('echo $__sandbox->livewire('.var_export($match[1], true).', '.$this->attributeArray($attributes, $line).');');

            return;
        }

        if (preg_match('/\G<\/\s*livewire:[\w\-:.]+\s*>/', $this->source, $match, 0, $this->position) === 1) {
            throw InvalidSandboxTemplateException::syntax('only self-closing <livewire:...> tags are supported', $line);
        }
        $this->literal('<');
    }

    /** @return array{0: list<array{kind: string, name: string, value: mixed}>, 1: bool} */
    private function attributes(int $line, bool $componentTag): array
    {
        $attributes = [];

        while (true) {
            if (preg_match('/\G\s*/', $this->source, $space, 0, $this->position) === 1) {
                $this->position += strlen($space[0]);
            }
            if ($this->position >= $this->length) {
                throw InvalidSandboxTemplateException::syntax('unclosed component tag', $line);
            }

            if (substr($this->source, $this->position, 2) === '/>') {
                $this->position += 2;
                return [$attributes, true];
            }
            if ($this->source[$this->position] === '>') {
                $this->position++;
                return [$attributes, false];
            }

            if (substr($this->source, $this->position, 2) === '{{') {
                $end = PhpBoundaryScanner::find($this->source, $this->position + 2, PhpBoundaryScanner::ECHO);
                if ($end === null) {
                    throw InvalidSandboxTemplateException::syntax('unterminated echo in component tag', $line);
                }
                $attributes[] = ['kind' => 'spread', 'name' => '', 'value' => substr($this->source, $this->position + 2, $end - $this->position - 2)];
                $this->position = $end + 2;

                continue;
            }

            if (preg_match('/\G@(class|style)\s*\(/', $this->source, $directive, 0, $this->position) === 1) {
                $start = $this->position + strlen($directive[0]);
                $end = PhpBoundaryScanner::find($this->source, $start, PhpBoundaryScanner::PARENTHESES);
                if ($end === null) {
                    throw InvalidSandboxTemplateException::syntax('unbalanced parentheses in @'.$directive[1], $line);
                }
                $attributes[] = ['kind' => $directive[1], 'name' => $directive[1], 'value' => substr($this->source, $start, $end - $start)];
                $this->position = $end + 1;

                continue;
            }

            if (preg_match('/\G(::|:\$|:)?([A-Za-z_@][\w\-:.@]*)/', $this->source, $name, 0, $this->position) !== 1) {
                throw InvalidSandboxTemplateException::syntax('invalid attribute in component tag', $line);
            }
            $this->position += strlen($name[0]);
            [$prefix, $attribute] = [$name[1], $name[2]];

            if ($prefix === ':$') {
                $attributes[] = ['kind' => 'bound', 'name' => $attribute, 'value' => '$'.lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $attribute))))];
                continue;
            }

            $value = null;
            if (preg_match('/\G\s*=\s*/', $this->source, $equals, 0, $this->position) === 1) {
                $this->position += strlen($equals[0]);
                $value = $this->attributeValue($line);
            }

            if ($prefix === ':') {
                if ($value === null) {
                    throw InvalidSandboxTemplateException::syntax('bound attribute :'.$attribute.' needs a value', $line);
                }
                if (LivewireGuard::isWireAttribute($attribute) || ($this->livewire->enforcesJavaScript() && LivewireGuard::isJavaScriptAttribute($attribute, true))) {
                    $this->livewire->checkAttribute($attribute, null, true, true);
                }
                $attributes[] = ['kind' => 'bound', 'name' => $attribute, 'value' => $value];

                continue;
            }

            $attributeName = $prefix === '::' ? ':'.$attribute : $attribute;
            $dynamic = $value !== null && (str_contains($value, '{{') || str_contains($value, '{!!'));
            $this->livewire->checkAttribute($attributeName, $value === null ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5), $dynamic, $componentTag && $prefix !== '::');
            $attributes[] = ['kind' => 'static', 'name' => $attributeName, 'value' => $value];
        }
    }

    private function attributeValue(int $line): string
    {
        $quote = $this->source[$this->position] ?? '';
        if ($quote === '"' || $quote === '\'') {
            $end = strpos($this->source, $quote, $this->position + 1);
            if ($end === false) {
                throw InvalidSandboxTemplateException::syntax('unterminated attribute value', $line);
            }
            $value = substr($this->source, $this->position + 1, $end - $this->position - 1);
            $this->position = $end + 1;

            return $value;
        }

        if (preg_match('/\G[^\s>"\'=<`]+/', $this->source, $match, 0, $this->position) === 1 && !str_ends_with($match[0], '/')) {
            $this->position += strlen($match[0]);
            if (str_contains($match[0], '{{')) {
                throw InvalidSandboxTemplateException::syntax('unquoted attribute value with dynamic content; quote the attribute value', $line);
            }

            return $match[0];
        }

        throw InvalidSandboxTemplateException::syntax('invalid attribute value', $line);
    }

    /**
     * @param list<array{kind: string, name: string, value: mixed}> $attributes
     *
     * @param-out list<array{kind: string, name: string, value: mixed}> $attributes
     */
    private function takeAttribute(array &$attributes, string $name, int $line): string
    {
        foreach ($attributes as $index => $attribute) {
            if ($attribute['name'] === $name && ($attribute['kind'] === 'bound' || $attribute['kind'] === 'static')) {
                $attributes = array_values(array_filter($attributes, static fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
                return $attribute['kind'] === 'bound' ? $this->expressions->expression((string) $attribute['value'], $line) : $this->staticValue($attribute['value'], $line);
            }
        }
        throw InvalidSandboxTemplateException::syntax('missing "'.$name.'" attribute', $line);
    }

    /**
     * @param list<array{kind: string, name: string, value: mixed}> $attributes
     *
     * @param-out list<array{kind: string, name: string, value: mixed}> $attributes
     */
    private function takeSlotName(array &$attributes, int $line): string
    {
        return $this->takeAttribute($attributes, 'name', $line);
    }

    /** @param list<array{kind: string, name: string, value: mixed}> $attributes */
    private function attributeArray(array $attributes, int $line): string
    {
        $items = [];
        foreach ($attributes as $attribute) {
            $key = var_export($attribute['name'], true);
            $items[] = match ($attribute['kind']) {
                'spread' => '...$__sandbox->spreadAttributes('.$this->expressions->expression((string) $attribute['value'], $line).')',
                'class' => $key.' => $__sandbox->classes('.implode(', ', $this->expressions->arguments((string) $attribute['value'], $line)).')',
                'style' => $key.' => $__sandbox->styles('.implode(', ', $this->expressions->arguments((string) $attribute['value'], $line)).')',
                'bound' => $key.' => '.$this->expressions->expression((string) $attribute['value'], $line),
                default => $key.' => '.($attribute['value'] === null ? 'true' : $this->staticValue($attribute['value'], $line)),
            };
        }

        return '['.implode(', ', $items).']';
    }

    /** @param list<array{kind: string, name: string, value: mixed}> $attributes */
    private function boundKeys(array $attributes): string
    {
        $keys = [];
        foreach ($attributes as $attribute) {
            if ($attribute['kind'] === 'bound') {
                $keys[] = var_export($attribute['name'], true);
            }
        }
        return '['.implode(', ', $keys).']';
    }

    private function staticValue(mixed $value, int $line): string
    {
        $value = (string) $value;
        $parts = [];
        $offset = 0;

        while (($start = strpos($value, '{{', $offset)) !== false) {
            if ($start > $offset) {
                $parts[] = var_export(substr($value, $offset, $start - $offset), true);
            }
            $end = PhpBoundaryScanner::find($value, $start + 2, PhpBoundaryScanner::ECHO);
            if ($end === null) {
                throw InvalidSandboxTemplateException::syntax('unterminated echo in attribute', $line);
            }
            $parts[] = '$__sandbox->componentAttribute('.$this->expressions->expression(substr($value, $start + 2, $end - $start - 2), $line).')';
            $offset = $end + 2;
        }
        if ($parts === [] || $offset < strlen($value)) {
            $parts[] = var_export(substr($value, $offset), true);
        }
        return implode(' . ', $parts);
    }

    //#endregion Component tags

    //#region Helpers

    private function line(): int
    {
        return $this->lineAt($this->position);
    }

    private function lineAt(int $offset): int
    {
        return substr_count($this->source, "\n", 0, min($offset, $this->length)) + 1;
    }
    //#endregion Helpers
}
