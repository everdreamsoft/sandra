<?php
declare(strict_types=1);

namespace SandraCore\Events;

class EventDispatcher
{
    /** @var array<string, callable[]> */
    private array $listeners = [];

    public function on(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    public function off(string $eventName, callable $listener): void
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        $this->listeners[$eventName] = array_values(
            array_filter($this->listeners[$eventName], fn($l) => $l !== $listener)
        );

        if (empty($this->listeners[$eventName])) {
            unset($this->listeners[$eventName]);
        }
    }

    public function dispatch(string $eventName, EntityEvent $event): EntityEvent
    {
        if (!isset($this->listeners[$eventName])) {
            return $event;
        }

        foreach ($this->listeners[$eventName] as $listener) {
            if ($event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }

    public function hasListeners(string $eventName): bool
    {
        return !empty($this->listeners[$eventName]);
    }
}
