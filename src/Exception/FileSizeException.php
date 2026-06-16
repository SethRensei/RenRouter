<?php
declare(strict_types=1);

namespace RenRouter\Exception;

use RuntimeException;

/**
 * Thrown when an uploaded file exceeds the allowed size limit.
 *
 * Supports a custom message with placeholders:
 *   {{ limit }}  → human-readable maximum size  (e.g. "2 MB")
 *   {{ actual }} → human-readable actual size   (e.g. "5.2 MB")
 *
 * Example:
 *   'Your file is too large. Maximum allowed size is {{ limit }}.'
 *   → "Your file is too large. Maximum allowed size is 2 MB."
 */
final class FileSizeException extends RuntimeException
{
    private int $actual;
    private int $max;

    public function __construct(
        int $actual,
        int $max,
        string $message = 'File size ({{ actual }}) exceeds the allowed limit of {{ limit }}.'
    ) {
        $this->actual = $actual;
        $this->max = $max;

        parent::__construct($this->interpolate($message));
    }

    /**
     * Actual uploaded file size in bytes.
     */
    public function getActual(): int
    {
        return $this->actual;
    }

    /**
     * Configured maximum size in bytes.
     */
    public function getMax(): int
    {
        return $this->max;
    }

    // -------------------------------------------------------------------------

    private function interpolate(string $template): string
    {
        return strtr($template, [
            '{{ limit }}' => self::humanize($this->max),
            '{{ actual }}' => self::humanize($this->actual),
        ]);
    }

    private static function humanize(int $bytes): string
    {
        if ($bytes >= 1_000_000) {
            return round($bytes / 1_000_000, 2) . ' MB';
        }
        if ($bytes >= 1_000) {
            return round($bytes / 1_000, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
