<?php

namespace Tests\Unit;

use App\Refresh\Clients\OnlyfansRuleSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/** Extracted rules are untrusted data: every field is shape-checked. */
class OnlyfansRuleSetTest extends TestCase
{
    private const VALID = [
        'revision' => '202609171554-a5a528bc87',
        'app_token' => '33d57ade8c02dbc5a333db99ff9ae26a',
        'static_param' => '1n9nB8zA91Eo8Ku4rnPbwTKf81YB9cOp',
        'prefix' => '65335',
        'suffix' => '6aac0d65',
        'checksum_indexes' => [2, 2, 3, 39],
        'checksum_constant' => 148,
    ];

    #[Test]
    public function a_valid_extraction_is_accepted(): void
    {
        $rules = OnlyfansRuleSet::fromArray(self::VALID, 'rulegen');

        $this->assertSame('202609171554-a5a528bc87', $rules->revision);
        $this->assertSame('33d57ade8c02dbc5a333db99ff9ae26a', $rules->signatureRules()['app-token']);
        $this->assertTrue($rules->sameRulesAs(OnlyfansRuleSet::fromArray(self::VALID, 'other-source')));
        $this->assertFalse($rules->sameRulesAs(OnlyfansRuleSet::fromArray(['checksum_constant' => 149] + self::VALID, 'rulegen')));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalid(): array
    {
        return [
            'bad revision' => [['revision' => 'latest'] + self::VALID],
            'bad app token' => [['app_token' => 'nope'] + self::VALID],
            'prefix with colon' => [['prefix' => '65:335'] + self::VALID],
            'static with newline' => [['static_param' => "abc\ndef"] + self::VALID],
            'index past sha1' => [['checksum_indexes' => [40]] + self::VALID],
            'no indexes' => [['checksum_indexes' => []] + self::VALID],
            'string constant' => [['checksum_constant' => '148'] + self::VALID],
            'huge constant' => [['checksum_constant' => 10_000_000] + self::VALID],
        ];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function malformed_rules_are_rejected(array $payload): void
    {
        $this->expectException(UnexpectedValueException::class);

        OnlyfansRuleSet::fromArray($payload, 'rulegen');
    }
}
