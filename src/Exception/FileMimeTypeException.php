<?php
declare(strict_types=1);

namespace RenRouter\Exception;

use RuntimeException;

/**
 * Thrown when an uploaded file has a disallowed MIME type.
 *
 * Supports a custom message with placeholders:
 *   {{ mimeType }} → the detected MIME type          (e.g. "application/zip")
 *   {{ allowed }}  → comma-separated list of allowed types
 *
 * Example:
 *   'The file type "{{ mimeType }}" is not allowed. Accepted types: {{ allowed }}.'
 *   → 'The file type "application/zip" is not allowed. Accepted types: image/jpeg, image/png.'
 */
final class FileMimeTypeException extends RuntimeException
{
    private string $mimeType;

    /** @var string[] */
    private array $allowed;

    /**
     * @param string   $mimeType Detected MIME type.
     * @param string[] $allowed  List of accepted MIME types.
     * @param string   $message  Optional custom message with placeholders.
     */
    public function __construct(
        string $mimeType,
        array $allowed,
        string $message = 'Unsupported MIME type "{{ mimeType }}". Allowed types: {{ allowed }}.'
    ) {
        $this->mimeType = $mimeType;
        $this->allowed = $allowed;

        parent::__construct($this->interpolate($message), 415);
    }

    /**
     * The MIME type that triggered the exception.
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * The list of accepted MIME types.
     *
     * @return string[]
     */
    public function getAllowed(): array
    {
        return $this->allowed;
    }

    // -------------------------------------------------------------------------

    private function interpolate(string $template): string
    {
        return strtr($template, [
            '{{ mimeType }}' => $this->mimeType,
            '{{ allowed }}' => implode(', ', $this->allowed),
        ]);
    }
}
