<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Integrity;

use InvalidArgumentException;
use JsonException;
use SytxLabs\BladeSandbox\Exceptions\TemplateIntegrityException;

/**
 * SHA-256 hashes of known-good view files, optionally signed with an HMAC key (the APP_KEY by default).
 *
 * A sandbox with a manifest (Sandbox::verifyIntegrity()) only renders view files whose name is listed
 * and whose current contents match the recorded hash. Files that were modified or added after the
 * manifest was created are refused. The signature prevents an attacker who can change view files
 * but not the application key from simply regenerating the manifest.
 */
final class IntegrityManifest
{
    public const ALGORITHM = 'sha256';

    /**
     * @param array<string, string> $hashes view name => sha256 hex digest
     */
    public function __construct(private readonly array $hashes, private readonly ?string $signature = null)
    {
    }

    /**
     * @param array<string, string> $files view name => path
     */
    public static function fromFiles(array $files, ?string $key = null): self
    {
        $hashes = [];
        foreach ($files as $view => $path) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new InvalidArgumentException('Cannot read view file for '.$view.'.');
            }
            $hashes[$view] = hash(self::ALGORITHM, $contents);
        }
        ksort($hashes);

        return new self($hashes, $key !== null ? self::sign($hashes, $key) : null);
    }

    /**
     * @param array{algorithm?: string, views?: array<string, string>, signature?: string|null} $data
     */
    public static function fromArray(array $data, ?string $key = null, bool $requireSignature = true): self
    {
        if (($data['algorithm'] ?? self::ALGORITHM) !== self::ALGORITHM || !is_array($data['views'] ?? null)) {
            throw TemplateIntegrityException::for('manifest', 'invalid format');
        }

        $hashes = [];
        foreach ($data['views'] as $view => $hash) {
            if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
                throw TemplateIntegrityException::for('manifest', 'invalid hash');
            }
            $hashes[$view] = $hash;
        }
        ksort($hashes);

        $signature = isset($data['signature']) && is_string($data['signature']) ? $data['signature'] : null;

        if ($key !== null && ($signature !== null || $requireSignature)) {
            if ($signature === null || !hash_equals(self::sign($hashes, $key), $signature)) {
                throw TemplateIntegrityException::for('manifest', $signature === null ? 'signature missing' : 'invalid signature');
            }
        }

        return new self($hashes, $signature);
    }

    public static function load(string $path, ?string $key = null, bool $requireSignature = true): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        try {
            $data = $contents === false ? null : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (!is_array($data)) {
            throw TemplateIntegrityException::for('manifest', 'cannot be read');
        }
        return self::fromArray($data, $key, $requireSignature);
    }

    public function has(string $view): bool
    {
        return isset($this->hashes[$view]);
    }

    /** Throws when the view is unknown or its contents differ from the recorded hash. */
    public function assert(string $view, string $contents): void
    {
        $expected = $this->hashes[$view] ?? null;
        if ($expected === null) {
            throw TemplateIntegrityException::for($view, 'view is not in the manifest');
        }
        if (!hash_equals($expected, hash(self::ALGORITHM, $contents))) {
            throw TemplateIntegrityException::for($view, 'contents changed');
        }
    }

    /** @return array<string, string> */
    public function hashes(): array
    {
        return $this->hashes;
    }

    public function isSigned(): bool
    {
        return $this->signature !== null;
    }

    /** @return array{algorithm: string, views: array<string, string>, signature: string|null} */
    public function toArray(): array
    {
        return ['algorithm' => self::ALGORITHM, 'views' => $this->hashes, 'signature' => $this->signature];
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /** @param array<string, string> $hashes */
    private static function sign(array $hashes, string $key): string
    {
        try {
            return hash_hmac(self::ALGORITHM, json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $key);
        } catch (JsonException) {
            return hash_hmac(self::ALGORITHM, serialize($hashes) ?: '', $key);
        }
    }
}
