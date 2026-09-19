<?php

namespace App\Refresh\Clients;

use UnexpectedValueException;

/**
 * One validated set of signing inputs for one OnlyFans web build. Built only
 * from locally extracted data (see OnlyfansRules::refresh) and never from a
 * remote rules feed; everything is shape-checked before it can sign anything.
 */
final readonly class OnlyfansRuleSet
{
    private const SHA1_LENGTH = 40;

    /** @param  list<int>  $checksumIndexes */
    private function __construct(
        public string $revision,
        public string $appToken,
        public string $staticParam,
        public string $prefix,
        public string $suffix,
        public array $checksumIndexes,
        public int $checksumConstant,
        public string $source,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload, string $source): self
    {
        $revision = self::requiredString($payload, 'revision', 64);
        if (preg_match('/\A20[0-9]{10}-[a-f0-9]{10}\z/', $revision) !== 1) {
            throw new UnexpectedValueException('invalid signing-rule revision');
        }

        $appToken = $payload['app-token'] ?? $payload['app_token'] ?? null;
        if (! is_string($appToken) || preg_match('/\A[a-f0-9]{32}\z/', $appToken) !== 1) {
            throw new UnexpectedValueException('invalid signing-rule app token');
        }

        $staticParam = self::requiredString($payload, 'static_param', 128);
        if (preg_match('/\A[\x21-\x7e]+\z/', $staticParam) !== 1) {
            throw new UnexpectedValueException('invalid signing-rule static_param');
        }
        $prefix = self::requiredToken($payload, 'prefix');
        $suffix = self::requiredToken($payload, 'suffix');

        $indexes = $payload['checksum_indexes'] ?? null;
        if (! is_array($indexes) || $indexes === [] || ! array_is_list($indexes)) {
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

        if (count($indexes) > 4 * self::SHA1_LENGTH) {
            throw new UnexpectedValueException('invalid signing-rule checksum indexes');
        }

        if ($source === '' || strlen($source) > 64) {
            throw new UnexpectedValueException('invalid signing-rule source');
        }

        return new self(
            $revision,
            $appToken,
            $staticParam,
            $prefix,
            $suffix,
            array_values($indexes),
            $constant,
            $source,
        );
    }

    /** @return array<string, mixed> */
    public function signatureRules(): array
    {
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
        return $this->signatureRules() + [
            'revision' => $this->revision,
        ];
    }

    /** Same build and same signing inputs, wherever each copy came from. */
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
