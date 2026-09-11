<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Refresh\InvalidPayload;
use App\Refresh\Outcome;
use App\Refresh\ProfileNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Group 1: the original failing format regression, old/new format acceptance,
 * and explicit zero versus missing or invalid likes.
 */
class ResponseFormatTest extends TestCase
{
    use RefreshDatabase;

    private Profile $profile;

    private ProfileNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile = $this->profile($this->account(), 'madison420ivy');
        $this->normalizer = new ProfileNormalizer;
    }

    #[Test]
    public function the_old_top_level_format_is_accepted(): void
    {
        $result = $this->normalizer->normalize(['likes' => 120000, 'revision' => 10], $this->profile, 'fixture');

        $this->assertSame(120000, $result->likes);
        $this->assertSame(10, $result->revision);
    }

    #[Test]
    public function the_new_nested_format_is_accepted(): void
    {
        // This is the exact payload shape that made the old handler store zero.
        $result = $this->normalizer->normalize(['profile' => ['likes' => 121000], 'revision' => 11], $this->profile, 'fixture');

        $this->assertSame(121000, $result->likes);
        $this->assertSame(11, $result->revision);
    }

    #[Test]
    public function explicit_zero_likes_is_valid_but_missing_likes_is_not(): void
    {
        $this->assertSame(0, $this->normalizer->normalize(['likes' => 0, 'revision' => 11], $this->profile, 'fixture')->likes);

        $this->expectException(InvalidPayload::class);
        $this->normalizer->normalize(['revision' => 11], $this->profile, 'fixture');
    }

    #[Test]
    public function disagreeing_locations_are_rejected(): void
    {
        $this->expectException(InvalidPayload::class);
        $this->normalizer->normalize(
            ['likes' => 121000, 'profile' => ['likes' => 999], 'revision' => 11],
            $this->profile,
            'fixture',
        );
    }

    #[Test]
    public function a_missing_revision_is_rejected_for_fixture_payloads(): void
    {
        $this->expectException(InvalidPayload::class);
        $this->normalizer->normalize(['likes' => 121000], $this->profile, 'fixture');
    }

    #[Test]
    public function a_mismatched_identity_is_rejected(): void
    {
        try {
            $this->normalizer->normalize(
                ['likes' => 121000, 'revision' => 11, 'username' => 'someone_else'],
                $this->profile,
                'fixture',
            );
            $this->fail('expected an identity failure');
        } catch (InvalidPayload $e) {
            $this->assertSame(Outcome::IDENTITY_MISMATCH, $e->category);
        }
    }

    #[Test]
    public function the_provider_envelope_maps_favorited_count_to_likes(): void
    {
        $result = $this->normalizer->normalize([
            'data' => [
                'id' => 5140520,
                'username' => 'madison420ivy',
                'name' => 'Madison Ivy',
                'favoritedCount' => 605771,  // received likes
                'favoritesCount' => 16,      // outgoing, must not be used
            ],
            '_meta' => ['_credits' => ['balance' => 98], '_cache' => ['note' => 'You used ?fresh=true to bypass the cache']],
        ], $this->profile, 'ofapi');

        $this->assertSame(605771, $result->likes);
        $this->assertSame('5140520', $result->upstreamId);
        // The provider exposes no revision; we do not invent one.
        $this->assertNull($result->revision);
        $this->assertFalse($result->fromCache);
        // Both counts are retained, and the credit envelope is not.
        $this->assertSame(16, $result->snapshot['favoritesCount']);
        $this->assertArrayNotHasKey('_meta', $result->snapshot);
    }
}
