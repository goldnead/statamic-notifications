<?php

namespace Goldnead\Notifications\Digest;

use Closure;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\DigestSource;
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

    /**
     * The contribution of every source that has something new to say, keyed by
     * handle. Each one carries at least `line`.
     *
     * @return array<string, array{line: string}>
     */
    public function collect(Identity $recipient, Carbon $since, Carbon $until): array
    {
        $collected = [];

        foreach ($this->sources as $handle => $source) {
            try {
                $resolved = $source instanceof Closure ? $source() : app($source);

                if (! $resolved instanceof DigestSource) {
                    continue;
                }

                $contribution = $resolved->collect($recipient, $since, $until);
                $line = $contribution['line'] ?? null;

                // The gate for every source, including the ones this package
                // has never heard of. Without a sentence a source contributes
                // nothing at all — not a line in the body, and not a reason to
                // send. Before 1.10.0 any non-empty return value counted as
                // content, which is how a source reporting a permanent state
                // kept an otherwise empty digest going out week after week.
                //
                // Whitespace is nothing too, or a blank row would keep the
                // digest alive in a quieter costume.
                //
                // What passes the gate is kept whole, not reduced to the
                // sentence. The shipped template prints `line` and nothing
                // else, but a host that published its own view reads the rest
                // — adriangoldner.com renders the event list out of it — and
                // handing that view a bare string would make its section
                // vanish without a word.
                if (is_string($line) && trim($line) !== '') {
                    $collected[$handle] = $contribution;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $collected;
    }
}
