<?php

declare(strict_types=1);

namespace Syntaxx\WebAssemblyPacker\Infra;

abstract class Event
{
    protected array $context = [];
    protected string $timestamp;

    public function __construct(array $context = [])
    {
        $this->context = $context;
        $this->timestamp = date('Y-m-d H:i:s');
    }

    abstract public function __toString(): string;

    public function getContext(): array
    {
        return $this->context;
    }

    public function getTimestamp(): string
    {
        return $this->timestamp;
    }
} 