<?php

declare(strict_types=1);

namespace Syntaxx\WebAssemblyPacker\Infra;

use Syntaxx\WebAssemblyPacker\Infra\Events\LogEvent;
use Syntaxx\WebAssemblyPacker\Infra\Events\FileProcessingEvent;
use Syntaxx\WebAssemblyPacker\Infra\Events\CompressionEvent;

class EventManager
{
    private array $listeners = [];
    private array $logLevels = [
        LogEvent::LEVEL_DEBUG => 0,
        LogEvent::LEVEL_INFO => 1,
        LogEvent::LEVEL_WARNING => 2,
        LogEvent::LEVEL_ERROR => 3
    ];
    private string $currentLogLevel = LogEvent::LEVEL_INFO;

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
     * Trigger an event
     */
    public function trigger(Event $event): void
    {
        $eventName = $event::class;
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $callback) {
            $callback($event);
        }
    }

    /**
     * Set the current log level
     */
    public function setLogLevel(string $level): void
    {
        if (!isset($this->logLevels[$level])) {
            throw new \InvalidArgumentException("Invalid log level: {$level}");
        }
        $this->currentLogLevel = $level;
    }

    /**
     * Log a message with a specific level
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if ($this->logLevels[$level] < $this->logLevels[$this->currentLogLevel]) {
            return;
        }

        $this->trigger(new LogEvent($level, $message, $context));
    }

    /**
     * Convenience methods for different log levels
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogEvent::LEVEL_DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogEvent::LEVEL_INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogEvent::LEVEL_WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogEvent::LEVEL_ERROR, $message, $context);
    }

    /**
     * Convenience methods for file processing events
     */
    public function fileProcessingStart(string $filePath, array $context = []): void
    {
        $this->trigger(new FileProcessingEvent(
            FileProcessingEvent::TYPE_START,
            $filePath,
            null,
            $context
        ));
    }

    public function fileProcessingComplete(string $filePath, array $context = []): void
    {
        $this->trigger(new FileProcessingEvent(
            FileProcessingEvent::TYPE_COMPLETE,
            $filePath,
            null,
            $context
        ));
    }

    public function fileProcessingError(string $filePath, string $error, array $context = []): void
    {
        $this->trigger(new FileProcessingEvent(
            FileProcessingEvent::TYPE_ERROR,
            $filePath,
            $error,
            $context
        ));
    }

    /**
     * Convenience methods for compression events
     */
    public function compressionStart(int $originalSize, array $context = []): void
    {
        $this->trigger(new CompressionEvent(
            CompressionEvent::TYPE_START,
            $originalSize,
            null,
            null,
            0.0,
            $context
        ));
    }

    public function compressionComplete(
        int $originalSize,
        int $compressedSize,
        float $duration,
        array $context = []
    ): void {
        $this->trigger(new CompressionEvent(
            CompressionEvent::TYPE_COMPLETE,
            $originalSize,
            $compressedSize,
            null,
            $duration,
            $context
        ));
    }

    public function compressionError(int $originalSize, string $error, array $context = []): void
    {
        $this->trigger(new CompressionEvent(
            CompressionEvent::TYPE_ERROR,
            $originalSize,
            null,
            $error,
            0.0,
            $context
        ));
    }
}
