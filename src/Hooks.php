<?php

namespace Livewirez\Billing;

use LogicException;


class Hooks
{
    /** @var array<string, array<int, list<callable>>> hook => priority => callbacks */
    protected array $listeners = [];

    public function on(string $hook, callable $callback, int $priority = 0): static
    {
        $this->listeners[$hook][$priority][] = $callback;

        return $this;
    }

    /**
     * Run callbacks in priority order (higher first).
     * A callback may return null (observe only) or a replacement payload of the same type.
     */
    public function run(string $hook, mixed $payload, array $context = []): mixed
    {
        if (empty($this->listeners[$hook])) {
            return $payload;
        }

        $byPriority = $this->listeners[$hook];
        krsort($byPriority);

        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as $callback) {
                $returned = $callback($payload, $context);

                if ($returned !== null) {
                    if (is_object($payload) && ! $returned instanceof $payload) {
                        throw new LogicException("Hook [{$hook}] must return " . $payload::class . ' or null.');
                    }
                    $payload = $returned;
                }
            }
        }

        return $payload;
    }
}