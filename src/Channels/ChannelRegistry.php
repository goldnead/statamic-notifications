<?php

namespace Goldnead\Notifications\Channels;

use Closure;
use Goldnead\Notifications\Contracts\Channel;

class ChannelRegistry
{
    /** @var array<string, string|Closure> */
    protected array $channels = [];

    public function register(string $handle, string|Closure $channel): static
    {
        $this->channels[$handle] = $channel;

        return $this;
    }

    public function resolve(string $handle): Channel
    {
        $channel = $this->channels[$handle] ?? null;

        if ($channel === null) {
            throw new \InvalidArgumentException("Unknown notification channel [{$handle}].");
        }

        return $channel instanceof Closure ? $channel() : app($channel);
    }

    public function has(string $handle): bool
    {
        return isset($this->channels[$handle]);
    }

    /** @return array<string, string|Closure> */
    public function all(): array
    {
        return $this->channels;
    }

    public function forget(): static
    {
        $this->channels = [];

        return $this;
    }
}
