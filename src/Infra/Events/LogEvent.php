<?php

declare(strict_types=1);

namespace Syntaxx\WebAssemblyPacker\Infra\Events;

use Syntaxx\WebAssemblyPacker\Infra\Event;

class LogEvent extends Event
{
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    private string $level;
    private string $message;

    public function __construct(string $level, string $message, array $context = [])
    {
        parent::__construct($context);
        $this->level = $level;
        $this->message = $message;
    }

    public function __toString(): string
    {
        return sprintf(
            "[%s] [%s] %s",
            $this->timestamp,
            strtoupper($this->level),
            $this->message
        );
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
} 