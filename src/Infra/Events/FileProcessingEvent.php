<?php

declare(strict_types=1);

namespace Syntaxx\WebAssemblyPacker\Infra\Events;

use Syntaxx\WebAssemblyPacker\Infra\Event;

class FileProcessingEvent extends Event
{
    public const TYPE_START = 'start';
    public const TYPE_COMPLETE = 'complete';
    public const TYPE_ERROR = 'error';

    private string $type;
    private string $filePath;
    private ?string $error = null;

    public function __construct(string $type, string $filePath, ?string $error = null, array $context = [])
    {
        parent::__construct($context);
        $this->type = $type;
        $this->filePath = $filePath;
        $this->error = $error;
    }

    public function __toString(): string
    {
        $message = match($this->type) {
            self::TYPE_START => "Processing file: {$this->filePath}",
            self::TYPE_COMPLETE => "Completed processing file: {$this->filePath}",
            self::TYPE_ERROR => "Error processing file {$this->filePath}: {$this->error}",
            default => "Unknown file processing event for {$this->filePath}"
        };

        return "[{$this->timestamp}] {$message}";
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
} 