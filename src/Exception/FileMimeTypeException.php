<?php
declare(strict_types=1);

namespace RenRouter\Exception;

use RuntimeException;

/**
 * Thrown when an uploaded file has a disallowed MIME type or a mismatched extension.
 *
 * Supports a custom message with placeholders:
 *   {{ mimeType }}   → the detected MIME type                    (e.g. "application/zip")
 *   {{ ext }}        → the submitted file extension              (e.g. ".zip")
 *   {{ allowed }}    → comma-separated list of allowed MIME types
 *   {{ allowedExt }} → comma-separated list of allowed extensions (e.g. ".jpg, .jpeg, .png")
 *
 * Example:
 *   'The file{{ ext }} is not accepted. Allowed formats: {{ allowedExt }}.'
 *   → 'The file (.zip) is not accepted. Allowed formats: .jpg, .jpeg, .png, .pdf.'
 */
final class FileMimeTypeException extends RuntimeException
{
    private string $mimeType;

    /** @var string[] */
    private array $allowed;

    private string $ext;

    /** @var string[] */
    private array $allowedExt;

    /**
     * @param string   $mimeType Detected MIME type.
     * @param string[] $allowed  List of accepted MIME types.
     * @param string   $ext      File extension (without leading dot).
     * @param string[] $allowedExt Flat list of all accepted extensions.
     * @param string   $message  Optional custom message with placeholders.
     */
    public function __construct(
        string $mimeType,
        array $allowed,
        string $ext = '',
        array $allowedExt = [],
        string $message = 'Unsupported MIME type "{{ mimeType }}"{{ ext }}. Allowed types: {{ allowed }}.'
    ) {
        $this->mimeType = $mimeType;
        $this->allowed = $allowed;
        $this->ext = $ext;
        $this->allowedExt = $allowedExt;

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

    /**
     * The flat list of all accepted extensions.
     *
     * @return string[]
     */
    public function getAllowedExt(): array
    {
        return $this->allowedExt;
    }

    // -------------------------------------------------------------------------

    private function interpolate(string $template): string
    {
        // {{ ext }} renders as " (.docx)" when present, or empty string when absent.
        $extDisplay = $this->ext !== '' ? ' (.' . $this->ext . ')' : '';

        // {{ allowedExt }} renders as ".jpg, .jpeg, .png, ..."
        $allowedExtDisplay = implode(', ', array_map(
            static fn(string $e) => '.' . $e,
            $this->allowedExt
        ));

        return strtr($template, [
            '{{ mimeType }}' => $this->mimeType,
            '{{ allowed }}' => implode(', ', $this->allowed),
            '{{ ext }}' => $extDisplay,
            '{{ allowedExt }}' => $allowedExtDisplay,
        ]);
    }
}