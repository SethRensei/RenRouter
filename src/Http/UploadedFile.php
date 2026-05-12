<?php
declare(strict_types=1);
namespace RenRouter\Http;

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
    ];

    /**
     * UploadedFile constructor.
     *
     * @param array $file Raw $_FILES entry
     * @throws RuntimeException
     */
    public function __construct(array $file, int $max_size = 2_000_000)
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload error.');
        }

        $this->file = $file;
        $this->max_size = $max_size;
    }

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
     * Detects the real MIME type of the file.
     *
     * @return string
     */    
    private function mimeType(): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string) $finfo->file($this->file['tmp_name']);
    }


    /**
     * Summary of setMineTypes
     * @param array $types Allowed MIME types
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
     * Returns the file extension.
     *
     * @return string
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->file['name'], PATHINFO_EXTENSION));
    }

    /**
     * Validates file size and MIME type.
     *
     * @throws RuntimeException
     */
    public function validate(): void
    {
        if ($this->size() > $this->max_size) {
            throw new RuntimeException('File size exceeds the allowed limit.');
        }

        $mime = $this->mimeType();
        if (!in_array($mime, $this->allowed_mime, true)) {
            throw new RuntimeException('Unsupported MIME type: ' . $mime, 415);
        }
    }

    /**
     * Checks if the file size is within the allowed limit.
     *
     * @return bool True if file size is acceptable, false otherwise
     */
    public function isGoodSize(): bool
    {
        return $this->size() <= $this->max_size;
    }

    /**
     * Moves the uploaded file to the given directory.
     *
     * @param string $directory
     * @param string|null $name Custom filename
     * @return string Final filename
     *
     * @throws RuntimeException
     */
    public function move(string $directory, ?string $name = null): string
    {
        $this->validate();

        if (!is_dir($directory))
            mkdir($directory, 0775, true);

        $filename = $name !== null
            ? sanitizeName($name) . '.' . $this->extension()
            : uniqid('upload_', true) . '.' . $this->extension();

        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($this->file['tmp_name'], $path)) {
            throw new RuntimeException('Failed to move uploaded file.');
        }

        return $filename;
    }
}