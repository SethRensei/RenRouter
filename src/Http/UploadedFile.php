<?php
declare(strict_types=1);
namespace RenRouter\Http;

use RenRouter\Exception\{FileMimeTypeException, FileSizeException};
use RuntimeException;

/**
 * Class UploadedFile
 *
 * Represents a single uploaded file and provides
 * validation and safe filesystem persistence.
 *
 * @package RenRouter\Http
 */
final class UploadedFile
{
    /**
     * @var array<string, mixed>
     */
    private array $file;

    /**
     * Maximum allowed file size (bytes).
     */
    private int $max_size;

    /**
     * Allowed MIME types.
     *
     * @var string[]
     */
    private array $allowed_mime = [
        'image/jpeg',
        'image/gif',
        'image/png',
        'image/webp',
        'application/pdf',
        'application/msword', // .doc
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
        'application/vnd.ms-excel', // .xls
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' // .xlsx
    ];

    /**
     * Custom message for FileSizeException.
     * Placeholders: {{ limit }}, {{ actual }}
     */
    private ?string $size_message = null;

    /**
     * Custom message for FileMimeTypeException.
     * Placeholders: {{ mimeType }}, {{ allowed }}
     */
    private ?string $mime_message = null;

    /**
     * Human-readable PHP upload error messages.
     * @var array<int, string>
     */
    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds the upload_max_filesize directive in php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds the MAX_FILE_SIZE directive in the HTML form.',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
    ];

    /**
     * @param array<string, mixed> $file     Raw $_FILES entry.
     * @param int                  $max_size Maximum allowed size in bytes (default 2 MB).
     *
     * @throws RuntimeException On any PHP upload error.
     */
    public function __construct(array $file, int $max_size = 2_000_000)
    {
        if (!isset($file['error'])) {
            throw new RuntimeException('Invalid $_FILES entry: missing error key.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = self::UPLOAD_ERRORS[$file['error']]
                ?? 'Unknown upload error (code ' . $file['error'] . ').';
            throw new RuntimeException($message);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Security violation: file was not uploaded via HTTP POST.');
        }

        $this->file = $file;
        $this->max_size = $max_size;
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Returns the original file name.
     *
     * @return string
     */
    public function originalName(): string
    {
        return $this->file['name'];
    }

    /**
     * Returns the file size in bytes.
     *
     * @return int
     */
    public function size(): int
    {
        return $this->file['size'];
    }

    /**
     * Returns the file extension.
     *
     * @return string
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->file['name'], PATHINFO_EXTENSION));
    }

    /**
     * Detects the real MIME type of the file.
     *
     * @return string
     */    
    private function mimeType(): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string) $finfo->file($this->file['tmp_name']);
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Replaces the list of allowed MIME types.
     *
     * @param string[] $types
     * @return UploadedFile
     */
    public function setMimeTypes(array $types): self
    {
        $this->allowed_mime = array_values($types);
        return $this;
    }

    /**
     * Sets the maximum allowed file size.
     *
     * @param int $size
     * @return self
     */
    public function setMaxSize(int $size): self
    {
        $this->max_size = $size;
        return $this;
    }

    /**
     * Defines a custom error message for size violations.
     *
     * Available placeholders:
     *   {{ limit }}  → human-readable max size  (e.g. "2 MB")
     *   {{ actual }} → human-readable file size (e.g. "5.2 MB")
     *
     * Example:
     *   $file->setSizeMessage('The file ({{ actual }}) is too large. Limit: {{ limit }}.');
     */
    public function setSizeMessage(string $message): self
    {
        $this->size_message = $message;
        return $this;
    }

    /**
     * Defines a custom error message for MIME type violations.
     *
     * Available placeholders:
     *   {{ mimeType }} → detected MIME type          (e.g. "application/zip")
     *   {{ allowed }}  → comma-separated allowed list
     *
     * Example:
     *   $file->setMimeMessage('"{{ mimeType }}" is not accepted. Use: {{ allowed }}.');
     */
    public function setMimeMessage(string $message): self
    {
        $this->mime_message = $message;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Returns true when the file size is within the allowed limit.
     */
    public function isValidSize(): bool
    {
        return $this->size() <= $this->max_size;
    }

    /**
     * Returns true when the MIME type is in the allowed list.
     */
    public function isValidMime(): bool
    {
        return in_array($this->mimeType(), $this->allowed_mime, true);
    }

    /**
     * Validates size and MIME type, throwing a typed exception for each failure.
     *
     * Custom messages can be set beforehand via setSizeMessage() / setMimeMessage().
     *
     * ```php
     * $file->setSizeMessage('File too big ({{ actual }}). Max: {{ limit }}.')
     *      ->setMimeMessage('"{{ mimeType }}" not allowed. Try: {{ allowed }}.')
     *      ->validate();
     * ```
     *
     * Catching them separately:
     * ```php
     * try {
     *     $file->validate();
     * } catch (FileSizeException $e) {
     *     // e.g. ask the user to compress the file
     * } catch (FileMimeTypeException $e) {
     *     // e.g. display accepted formats
     * }
     * ```
     *
     * @throws FileSizeException     When the file exceeds the size limit.
     * @throws FileMimeTypeException When the MIME type is not allowed.
     */
    public function validate(): void
    {
        if (!$this->isValidSize()) {
            $args = [$this->size(), $this->max_size];
            if ($this->size_message !== null) {
                $args[] = $this->size_message;
            }
            throw new FileSizeException(...$args);
        }

        if (!$this->isValidMime()) {
            $args = [$this->mimeType(), $this->allowed_mime];
            if ($this->mime_message !== null) {
                $args[] = $this->mime_message;
            }
            throw new FileMimeTypeException(...$args);
        }
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    /**
     * Validates and moves the uploaded file to the given directory.
     *
     * @param string      $directory  Destination directory (created if absent).
     * @param string|null $name       Custom base name without extension.
     *                                Defaults to a unique token.
     * @return string                 Final filename (base name only, not full path).
     *
     * @throws FileSizeException
     * @throws FileMimeTypeException
     * @throws RuntimeException On filesystem errors.
     */
    public function move(string $directory, ?string $name = null): string
    {
        $this->validate();

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory "%s" could not be created.', $directory));
        }

        $basename = $name !== null
            ? $this->sanitizeName($name) . '.' . $this->extension()
            : uniqid('upload_', true) . '.' . $this->extension();

        $destination = rtrim($directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $basename;

        if (!move_uploaded_file($this->file['tmp_name'], $destination)) {
            throw new RuntimeException(sprintf('Failed to move uploaded file to "%s".', $destination));
        }

        return $basename;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Strips characters unsafe in filenames and collapses whitespace to dashes.
     */
    private function sanitizeName(string $name): string
    {
        $safe = preg_replace('/[\x00\/\\\\]/', '', $name);
        $safe = preg_replace('/\s+/', '-', trim((string) $safe));
        return $safe !== '' ? $safe : 'file';
    }
}