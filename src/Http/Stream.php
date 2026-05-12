<?php

namespace RenRouter\Http;

use RenRouter\Http\Contracts\StreamInterface;
use RenRouter\Http\Exception\StreamException;

final class Stream implements StreamInterface
{
    /**
     * @var resource|null
     */
    private $stream;

    /**
     * @param resource $stream
     */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new StreamException('Invalid stream resource.');
        }

        $this->stream = $stream;
    }

    public function __toString(): string
    {
        if ($this->stream === null) {
            return '';
        }

        try {
            $this->rewind();
            return stream_get_contents($this->stream);
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function detach()
    {
        $resource = $this->stream;
        $this->stream = null;

        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->stream === null) {
            return null;
        }

        $stats = fstat($this->stream);

        return $stats['size'] ?? null;
    }

    public function tell(): int
    {
        $position = ftell($this->stream);

        if ($position === false) {
            throw new StreamException('Unable to determine stream position.');
        }

        return $position;
    }

    public function eof(): bool
    {
        return feof($this->stream);
    }

    public function isSeekable(): bool
    {
        $meta = stream_get_meta_data($this->stream);

        return $meta['seekable'];
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable()) {
            throw new StreamException('Stream is not seekable.');
        }

        if (fseek($this->stream, $offset, $whence) !== 0) {
            throw new StreamException('Unable to seek stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        $mode = $this->getMetadata('mode');

        return str_contains($mode, 'w')
            || str_contains($mode, '+')
            || str_contains($mode, 'a')
            || str_contains($mode, 'x')
            || str_contains($mode, 'c');
    }

    public function write(string $string): int
    {
        if (!$this->isWritable()) {
            throw new StreamException('Stream is not writable.');
        }

        $result = fwrite($this->stream, $string);

        if ($result === false) {
            throw new StreamException('Unable to write to stream.');
        }

        return $result;
    }

    public function isReadable(): bool
    {
        $mode = $this->getMetadata('mode');

        return str_contains($mode, 'r') || str_contains($mode, '+');
    }

    public function read(int $length): string
    {
        if (!$this->isReadable()) {
            throw new StreamException('Stream is not readable.');
        }

        $result = fread($this->stream, $length);

        if ($result === false) {
            throw new StreamException('Unable to read stream.');
        }

        return $result;
    }

    public function getContents(): string
    {
        $contents = stream_get_contents($this->stream);

        if ($contents === false) {
            throw new StreamException('Unable to read stream contents.');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        $metadata = stream_get_meta_data($this->stream);

        if ($key === null) {
            return $metadata;
        }

        return $metadata[$key] ?? null;
    }
}
