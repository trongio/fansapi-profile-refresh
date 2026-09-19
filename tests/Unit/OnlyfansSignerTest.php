<?php

namespace Tests\Unit;

use App\Refresh\Clients\OnlyfansSigner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The signing algorithm is a pure function, so it is pinned against a fixed
 * vector: same rules, same path, same timestamp must give the same sign every
 * time. This proves the checksum maths, not that OnlyFans accepts the result.
 */
class OnlyfansSignerTest extends TestCase
{
    private const RULES = [
        'app-token' => 'ATOKEN',
        'static_param' => 'STATIC',
        'prefix' => 'PRE',
        'suffix' => 'SUF',
        'checksum_constant' => 10,
        'checksum_indexes' => [0, 5, 10, 15, 20],
    ];

    #[Test]
    public function it_signs_deterministically_for_a_fixed_timestamp(): void
    {
        $a = OnlyfansSigner::headers(self::RULES, '/api2/v2/users/madison420ivy', '0', 1700000000000);
        $b = OnlyfansSigner::headers(self::RULES, '/api2/v2/users/madison420ivy', '0', 1700000000000);

        $this->assertSame($a, $b);
        $this->assertSame('ATOKEN', $a['app-token']);
        $this->assertSame('0', $a['user-id']);
        $this->assertSame('1700000000000', $a['time']);
    }

    #[Test]
    public function the_sign_has_the_prefix_sha_checksum_suffix_shape(): void
    {
        $headers = OnlyfansSigner::headers(self::RULES, '/api2/v2/users/madison420ivy', '0', 1700000000000);

        [$prefix, $sha, $checksum, $suffix] = explode(':', $headers['sign']);

        $this->assertSame('PRE', $prefix);
        $this->assertSame('SUF', $suffix);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $sha, 'middle segment is a sha1 hex digest');
        $this->assertMatchesRegularExpression('/\A[0-9a-f]+\z/', $checksum, 'checksum is lowercase hex');

        // Recompute the checksum independently.
        $expected = self::RULES['checksum_constant'];
        foreach (self::RULES['checksum_indexes'] as $i) {
            $expected += ord($sha[$i]);
        }
        $this->assertSame(dechex($expected), $checksum);
    }

    #[Test]
    public function a_different_path_produces_a_different_signature(): void
    {
        $one = OnlyfansSigner::headers(self::RULES, '/api2/v2/users/madison420ivy', '0', 1700000000000);
        $two = OnlyfansSigner::headers(self::RULES, '/api2/v2/users/someoneelse', '0', 1700000000000);

        $this->assertNotSame($one['sign'], $two['sign']);
    }

    /**
     * Cross-language pin: the same vectors are checked by the Node reference
     * signer in services/rulegen, so PHP and the extractor cannot drift.
     */
    #[Test]
    public function it_reproduces_the_shared_cross_language_vectors(): void
    {
        $contract = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/services/rulegen/contract/sign-vectors.json'), true, 8, JSON_THROW_ON_ERROR);

        foreach ($contract['cases'] as $case) {
            foreach ($case['vectors'] as $vector) {
                $headers = OnlyfansSigner::headers($case['rules'], $vector['path'], $vector['user_id'], (int) $vector['time']);
                $this->assertSame($vector['sign'], $headers['sign'], $vector['path']);
            }
        }
    }
}
