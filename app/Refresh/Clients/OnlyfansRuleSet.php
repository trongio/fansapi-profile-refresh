<?php

namespace App\Refresh\Clients;

use LogicException;
use UnexpectedValueException;

/**
 * One validated signer for one OnlyFans web build, built only from locally
 * extracted data (see OnlyfansRuleRefresher) and never from a remote rules
 * feed. Everything is shape-checked before it can sign anything.
 *
 * Two modes:
 *  - constants: the build still uses the known formula; PHP signs by itself
 *    with OnlyfansSigner and the extracted constants.
 *  - delegated: the build changed the formula, so the extracted function
 *    itself is the signer; each signature comes from the rulegen service.
 */
final readonly class OnlyfansRuleSet
{
    public const CONSTANTS = 'constants';

    public const DELEGATED = 'delegated';

    private const SHA1_LENGTH = 40;

    /** @param  list<int>|null  $checksumIndexes */
    private function __construct(
        public string $mode,
        public string $revision,
        public string $appToken,
        public ?string $staticParam,
        public ?string $prefix,
        public ?string $suffix,
        public ?array $checksumIndexes,
        public ?int $checksumConstant,
        public string $source,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload, string $source): self
    {
        $mode = $payload['mode'] ?? self::CONSTANTS;
        if (! in_array($mode, [self::CONSTANTS, self::DELEGATED], true)) {
            throw new UnexpectedValueException('invalid signing-rule mode');
        }

        $revision = self::requiredString($payload, 'revision', 64);
        if (preg_match('/\A20[0-9]{10}-[a-f0-9]{10}\z/', $revision) !== 1) {
            throw new UnexpectedValueException('invalid signing-rule revision');
        }

        $appToken = $payload['app-token'] ?? $payload['app_token'] ?? null;
        if (! is_string($appToken) || preg_match('/\A[a-f0-9]{32}\z/', $appToken) !== 1) {
            throw new UnexpectedValueException('invalid signing-rule app token');
        }

        if ($source === '' || strlen($source) > 64) {
            throw new UnexpectedValueException('invalid signing-rule source');
        }

        if ($mode === self::DELEGATED) {
            return new self($mode, $revision, $appToken, null, null, null, null, null, $source);
        }

        $staticParam = self::requiredString($payload, 'static_param', 128);
        if (preg_match('/\A[\x21-\x7e]+\z/', $staticParam) !== 1) {
            throw new UnexpectedValueException('invalid signing-rule static_param');
        }
        $prefix = self::requiredToken($payload, 'prefix');
        $suffix = self::requiredToken($payload, 'suffix');

        $indexes = $payload['checksum_indexes'] ?? null;
        if (! is_array($indexes) || $indexes === [] || ! array_is_list($indexes) || count($indexes) > 4 * self::SHA1_LENGTH) {
            throw new UnexpectedValueException('invalid signing-rule checksum indexes');
        }

        foreach ($indexes as $index) {
            if (! is_int($index) || $index < 0 || $index >= self::SHA1_LENGTH) {
                throw new UnexpectedValueException('invalid signing-rule checksum index');
            }
        }

        $constant = $payload['checksum_constant'] ?? null;
        if (! is_int($constant) || abs($constant) > 1_000_000) {
            throw new UnexpectedValueException('invalid signing-rule checksum constant');
        }

        return new self($mode, $revision, $appToken, $staticParam, $prefix, $suffix, array_values($indexes), $constant, $source);
    }

    public function delegated(): bool
    {
        return $this->mode === self::DELEGATED;
    }

    /** @return array<string, mixed> Inputs for OnlyfansSigner (constants mode only). */
    public function signatureRules(): array
    {
        if ($this->delegated()) {
            throw new LogicException("build {$this->revision} is signed by its extracted function, not by constants");
        }

        return [
            'app-token' => $this->appToken,
            'static_param' => $this->staticParam,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'checksum_indexes' => $this->checksumIndexes,
            'checksum_constant' => $this->checksumConstant,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $base = ['mode' => $this->mode, 'revision' => $this->revision, 'app-token' => $this->appToken];

        return $this->delegated() ? $base : $base + $this->signatureRules();
    }

    /** Same build and same signer, wherever each copy came from. */
    public function sameRulesAs(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /** @param array<string, mixed> $payload */
    private static function requiredString(array $payload, string $key, int $maxLength): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new UnexpectedValueException("invalid signing-rule {$key}");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function requiredToken(array $payload, string $key): string
    {
        $value = self::requiredString($payload, $key, 64);
        if (preg_match('/\A[A-Za-z0-9]+\z/', $value) !== 1) {
            throw new UnexpectedValueException("invalid signing-rule {$key}");
        }

        return $value;
    }
}
