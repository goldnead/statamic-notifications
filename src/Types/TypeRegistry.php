<?php

namespace Goldnead\Notifications\Types;

use Closure;

/**
 * Every notification type the application knows about. Unregistered types are
 * still deliverable — they simply fall back to in-app only and render whatever
 * the producer passed. That keeps a missing registration from silently dropping
 * someone's notification.
 */
class TypeRegistry
{
    /** @var array<string, NotificationType> */
    protected array $types = [];

    public function register(string|NotificationType $type, ?Closure $configure = null): NotificationType
    {
        $definition = $type instanceof NotificationType ? $type : NotificationType::make($type);

        if ($configure !== null) {
            $configure($definition);
        }

        $this->types[$definition->handle] = $definition;

        return $definition;
    }

    public function get(string $handle): NotificationType
    {
        return $this->types[$handle] ?? NotificationType::make($handle);
    }

    public function has(string $handle): bool
    {
        return isset($this->types[$handle]);
    }

    /** @return array<string, NotificationType> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return array<int, string> */
    public function handles(): array
    {
        return array_keys($this->types);
    }

    public function forget(): static
    {
        $this->types = [];

        return $this;
    }
}
