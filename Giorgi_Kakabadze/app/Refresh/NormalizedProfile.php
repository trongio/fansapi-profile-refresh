<?php

namespace App\Refresh;

/** The validated result of one upstream body. Nothing else reaches the writer. */
final class NormalizedProfile
{
    public function __construct(
        public readonly int $likes,
        public readonly ?int $revision,   // null = source exposes no upstream revision
        public readonly ?string $upstreamId,
        public readonly string $username,
        public readonly ?string $displayName,
        /** @var array<string,mixed> bounded, validated metadata snapshot */
        public readonly array $snapshot,
        public readonly bool $fromCache = false,
    ) {}
}
