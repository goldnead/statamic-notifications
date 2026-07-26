<?php

namespace Goldnead\Notifications\Digest;

use Closure;
use Goldnead\Notifications\Contracts\DigestSource;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Support\Carbon;

/**
 * `Notifications::registerSource('community', CommunityDigestSource::class)`.
 *
 * A failing source must never take down the whole digest — one addon's broken
 * query would otherwise silence everybody's weekly mail.
 */
class SourceRegistry
{
    /** @var array<string, string|Closure> */
    protected array $sources = [];

    public function register(string $handle, string|Closure $source): static
    {
        $this->sources[$handle] = $source;

        return $this;
    }

    public function has(string $handle): bool
    {
        return isset($this->sources[$handle]);
    }

    /** @return array<int, string> */
    public function handles(): array
    {
        return array_keys($this->sources);
    }

    public function forget(): static
    {
        $this->sources = [];

        return $this;
    }

    /** @return array<string, mixed> */
    public function collect(Identity $recipient, Carbon $windowStart, Carbon $windowEnd): array
    {
        $collected = [];

        foreach ($this->sources as $handle => $source) {
            try {
                $resolved = $source instanceof Closure ? $source() : app($source);

                if (! $resolved instanceof DigestSource) {
                    continue;
                }

                $contribution = $resolved->collect($recipient, $windowStart, $windowEnd);

                if ($contribution !== []) {
                    $collected[$handle] = $contribution;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $collected;
    }
}
