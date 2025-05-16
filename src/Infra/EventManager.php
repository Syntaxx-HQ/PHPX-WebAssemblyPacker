<?php

declare(strict_types=1);

namespace PHPX\WebAssemblyPacker\Infra;

class EventManager
{
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    private array $listeners = [];
    private array $logLevels = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR => 3
    ];
    private string $currentLogLevel = self::LEVEL_INFO;

    /**
     * Add a listener for a specific event
     */
    public function addListener(string $event, callable $callback): void
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $callback;
    }

    /**
     * Remove a specific listener for an event
     */
    public function removeListener(string $event, callable $callback): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        $this->listeners[$event] = array_filter(
            $this->listeners[$event],
            fn($listener) => $listener !== $callback
        );
    }

    /**
     * Trigger an event with optional data
     */
    public function trigger(string $event, array $data = []): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $callback) {
            $callback($data);
        }
    }
}
