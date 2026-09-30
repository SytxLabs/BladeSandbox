<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compiler;

/** Finds where an embedded PHP expression ends inside Blade source, using PHP's own tokenizer so delimiters inside string literals or nested brackets are not mistaken for the end. */
final class PhpBoundaryScanner
{
    public const PARENTHESES = 'paren';

    public const ECHO = 'echo';

    public const TRIPLE_ECHO = 'echo3';

    public const RAW_ECHO = 'raw';

    private const WINDOW = 4096;

    /**
     * @param int $start offset of the first character of the expression (after the opening delimiter)
     *
     * @return int|null offset of the closing delimiter, or null when it cannot be found
     */
    public static function find(string $source, int $start, string $mode): ?int
    {
        $length = strlen($source);
        $window = self::WINDOW;

        while (true) {
            $result = self::scan(substr($source, $start, $window), $mode);
            if ($result !== null) {
                return $start + $result;
            }
            if ($start + $window >= $length) {
                return null;
            }
            $window *= 4;
        }
    }

    private static function scan(string $chunk, string $mode): ?int
    {
        set_error_handler(static fn (): bool => true);
        try {
            $tokens = token_get_all('<?php '.$chunk);
        } finally {
            restore_error_handler();
        }
        $offset = -6;
        $depth = 0;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $position = $offset;
            $offset += strlen($text);

            if ($position < 0) {
                continue;
            }

            if (is_array($token)) {
                if ($token[0] === T_CLOSE_TAG || $token[0] === T_INLINE_HTML || $token[0] === T_OPEN_TAG || $token[0] === T_OPEN_TAG_WITH_ECHO) {
                    return null;
                }
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                }

                continue;
            }

            switch ($text) {
                case '(':
                case '[':
                case '{':
                    $depth++;
                    break;
                case ')':
                    if ($depth === 0 && $mode === self::PARENTHESES) {
                        return $position;
                    }
                    $depth--;
                    break;
                case ']':
                    $depth--;
                    break;
                case '}':
                    if ($depth === 0) {
                        if ($mode === self::ECHO && substr($chunk, $position, 2) === '}}') {
                            return $position;
                        }
                        if ($mode === self::TRIPLE_ECHO && substr($chunk, $position, 3) === '}}}') {
                            return $position;
                        }
                    }
                    $depth--;
                    break;
                case '!':
                    if ($depth === 0 && $mode === self::RAW_ECHO && substr($chunk, $position, 3) === '!!}') {
                        return $position;
                    }
                    break;
            }
        }

        return null;
    }
}
