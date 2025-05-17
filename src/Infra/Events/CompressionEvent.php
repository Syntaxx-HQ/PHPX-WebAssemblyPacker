<?php

declare(strict_types=1);

namespace Syntaxx\WebAssemblyPacker\Infra\Events;

use Syntaxx\WebAssemblyPacker\Infra\Event;

class CompressionEvent extends Event
{
    public const TYPE_START = 'start';
    public const TYPE_COMPLETE = 'complete';
    public const TYPE_ERROR = 'error';

    private string $type;
    private int $originalSize;
    private ?int $compressedSize = null;
    private ?string $error = null;
    private float $duration = 0.0;

    public function __construct(
        string $type,
        int $originalSize,
        ?int $compressedSize = null,
        ?string $error = null,
        float $duration = 0.0,
        array $context = []
    ) {
        parent::__construct($context);
        $this->type = $type;
        $this->originalSize = $originalSize;
        $this->compressedSize = $compressedSize;
        $this->error = $error;
        $this->duration = $duration;
    }

    public function __toString(): string
    {
        $message = match($this->type) {
            self::TYPE_START => "Starting compression of {$this->originalSize} bytes",
            self::TYPE_COMPLETE => sprintf(
                "Compression complete: %d bytes -> %d bytes (%.2f%% reduction) in %.2f ms",
                $this->originalSize,
                $this->compressedSize,
                $this->getCompressionRatio(),
                $this->duration * 1000
            ),
            self::TYPE_ERROR => "Compression error: {$this->error}",
            default => "Unknown compression event"
        };

        return "[{$this->timestamp}] {$message}";
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOriginalSize(): int
    {
        return $this->originalSize;
    }

    public function getCompressedSize(): ?int
    {
        return $this->compressedSize;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getCompressionRatio(): float
    {
        if ($this->compressedSize === null || $this->originalSize === 0) {
            return 0.0;
        }
        return (1 - ($this->compressedSize / $this->originalSize)) * 100;
    }
} 