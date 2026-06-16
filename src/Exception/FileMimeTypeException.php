<?php
declare(strict_types=1);

namespace RenRouter\Exception;

use RuntimeException;

/**
 * Thrown when an uploaded file has a disallowed MIME type or a mismatched extension.
 *
 * Supports a custom message with placeholders:
 *   {{ mimeType }} → the detected MIME type               (e.g. "application/zip")
 *   {{ allowed }}  → comma-separated list of allowed MIME types
 *   {{ ext }}      → the file extension submitted         (e.g. "docx")
 *
 * Example:
 *   '"{{ mimeType }}" (.{{ ext }}) is not allowed. Accepted types: {{ allowed }}.'
 *   → '"application/zip" (.zip) is not allowed. Accepted types: image/jpeg, image/png.'
 */
final class FileMimeTypeException extends RuntimeException
{
    private string $mimeType;

    /** @var string[] */
    private array $allowed;

    private string $ext;

    /**
     * @param string   $mimeType Detected MIME type.
     * @param string[] $allowed  List of accepted MIME types.
     * @param string   $ext      File extension (without leading dot).
     * @param string   $message  Optional custom message with placeholders.
     */
    public function __construct(
        string $mimeType,
        array $allowed,
        string $ext = '',
        string $message = 'Unsupported MIME type "{{ mimeType }}"{{ ext }}. Allowed types: {{ allowed }}.'
    ) {
        $this->mimeType = $mimeType;
        $this->allowed = $allowed;
        $this->ext = $ext;

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

    /**
     * The file extension that triggered the exception.
     */
    public function getExt(): string
    {
        return $this->ext;
    }

    // -------------------------------------------------------------------------

    private function interpolate(string $template): string
    {
        // {{ ext }} renders as " (.docx)" when present, or empty string when absent.
        $extDisplay = $this->ext !== '' ? ' (.' . $this->ext . ')' : '';

        return strtr($template, [
            '{{ mimeType }}' => $this->mimeType,
            '{{ allowed }}' => implode(', ', $this->allowed),
            '{{ ext }}' => $extDisplay,
        ]);
    }
}