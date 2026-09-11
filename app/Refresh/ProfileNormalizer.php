<?php

namespace App\Refresh;

use App\Models\Profile;

/**
 * Accepted response shapes and the field rules, in one place.
 *
 *  1. legacy fixture : {"likes": 120000, "revision": 10}
 *  2. new fixture    : {"profile": {"likes": 121000}, "revision": 11}
 *  3. live provider  : {"data": {"favoritedCount": 605771, ...}, "_meta": {...}}
 *
 * favoritedCount is the count of OTHER users who favourited this profile, i.e.
 * received likes. favoritesCount is what this account itself favourited; it is
 * retained in the snapshot but never used as the likes value.
 */
class ProfileNormalizer
{
    /**
     * @param  array<string,mixed>  $body
     *
     * @throws InvalidPayload
     */
    public function normalize(array $body, Profile $profile, string $source): NormalizedProfile
    {
        return match ($source) {
            'ofapi' => $this->normalizeProvider($body, $profile),
            // Direct OnlyFans returns the profile object unwrapped; the fields
            // (favoritedCount, favoritesCount, id, username) are identical.
            'onlyfans' => $this->normalizeProvider(['data' => $body], $profile),
            default => $this->normalizeFixture($body, $profile),
        };
    }

    /** @param array<string,mixed> $body */
    private function normalizeFixture(array $body, Profile $profile): NormalizedProfile
    {
        $hasFlat = array_key_exists('likes', $body);
        $nested = $body['profile'] ?? null;
        $hasNested = is_array($nested) && array_key_exists('likes', $nested);

        if (! $hasFlat && ! $hasNested) {
            // Missing is an invalid response. Zero is valid information.
            throw InvalidPayload::schema('likes is missing from both the top level and profile.likes');
        }

        $likes = null;
        if ($hasFlat) {
            $likes = CountValue::parse($body['likes'], 'likes');
        }
        if ($hasNested) {
            $nestedLikes = CountValue::parse($nested['likes'], 'profile.likes');
            if ($likes !== null && $likes !== $nestedLikes) {
                throw InvalidPayload::schema('likes and profile.likes disagree');
            }
            $likes = $nestedLikes;
        }

        if (! array_key_exists('revision', $body)) {
            throw InvalidPayload::schema('revision is missing');
        }
        $revision = CountValue::parse($body['revision'], 'revision');

        $username = $this->stringOr($nested['username'] ?? $body['username'] ?? null) ?? $profile->username;
        $upstreamId = $this->idOr($nested['id'] ?? $body['id'] ?? null);

        $this->assertIdentity($profile, $upstreamId, $username);

        return new NormalizedProfile(
            likes: $likes,
            revision: $revision,
            upstreamId: $upstreamId,
            username: $username,
            displayName: $this->stringOr($nested['name'] ?? $body['name'] ?? null),
            snapshot: $this->boundSnapshot(is_array($nested) ? $nested : $body),
        );
    }

    /** @param array<string,mixed> $body */
    private function normalizeProvider(array $body, Profile $profile): NormalizedProfile
    {
        $data = $body['data'] ?? null;
        if (! is_array($data) || $data === []) {
            throw InvalidPayload::schema('provider response has no data object');
        }

        if (! array_key_exists('favoritedCount', $data)) {
            throw InvalidPayload::schema('favoritedCount is missing');
        }
        $likes = CountValue::parse($data['favoritedCount'], 'favoritedCount');

        $username = $this->stringOr($data['username'] ?? null);
        if ($username === null) {
            throw InvalidPayload::schema('username is missing');
        }
        $upstreamId = $this->idOr($data['id'] ?? null);
        if ($upstreamId === null) {
            throw InvalidPayload::schema('id is missing');
        }

        $this->assertIdentity($profile, $upstreamId, $username);

        // The provider exposes no version/revision field, so we do not invent
        // one from timestamps, hashes or request order. Live ordering is
        // protected by the run lease + idempotency instead.
        return new NormalizedProfile(
            likes: $likes,
            revision: null,
            upstreamId: $upstreamId,
            username: $username,
            displayName: $this->stringOr($data['name'] ?? null),
            snapshot: $this->boundSnapshot($data),
            fromCache: ! str_contains((string) data_get($body, '_meta._cache.note', ''), 'bypass the cache'),
        );
    }

    private function assertIdentity(Profile $profile, ?string $upstreamId, string $username): void
    {
        if ($profile->upstream_id !== null && $upstreamId !== null && $profile->upstream_id !== $upstreamId) {
            throw InvalidPayload::identity("upstream id {$upstreamId} does not match stored {$profile->upstream_id}");
        }

        if (strcasecmp($username, $profile->username) !== 0) {
            throw InvalidPayload::identity("username {$username} does not match stored {$profile->username}");
        }
    }

    /**
     * Preserve all available metadata, minus the provider's credit and
     * rate-limit envelope, which must never reach storage or logs.
     *
     * There is exactly ONE size limit in the system and it is enforced on the
     * wire (config fansapi.http.max_body_bytes, 1 MiB): anything that arrives
     * has already been bounded, and the snapshot is never truncated.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function boundSnapshot(array $data): array
    {
        unset($data['_meta'], $data['_credits']);

        if (json_encode($data) === false) {
            throw InvalidPayload::schema('snapshot is not encodable as JSON');
        }

        return $data;
    }

    private function stringOr(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function idOr(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
