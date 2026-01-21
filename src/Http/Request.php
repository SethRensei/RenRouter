<?php
declare(strict_types=1);
namespace RenRouter\Http;

/**
 * Class Request
 *
 * Encapsulates the HTTP request data and provides a clean,
 * testable and secure API to access user input.
 *
 * This class abstracts PHP superglobals ($_GET, $_POST, $_FILES, $_SERVER)
 * and should be the single entry point for request data.
 *
 * @package RenRouter\Http
 */
final class Request
{
    /**
     * @var array<string, mixed>
     */
    private array $get;

    /**
     * @var array<string, mixed>
     */
    private array $post;

    /**
     * @var array<string, array>
     */
    private array $files;

    /**
     * @var array<string, mixed>
     */
    private array $server;

    /**
     * Request constructor.
     *
     * Allows dependency injection for easier testing.
     *
     * @param array|null $get
     * @param array|null $post
     * @param array|null $files
     * @param array|null $server
     */
    public function __construct(
        ?array $get = null,
        ?array $post = null,
        ?array $files = null,
        ?array $server = null
    ) {
        $this->get = $get ?? $_GET;
        $this->post = $post ?? $_POST;
        $this->files = $files ?? $_FILES;
        $this->server = $server ?? $_SERVER;
    }

    /**
     * Returns the HTTP request method.
     *
     * @return string
     */
    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Checks whether the request method is POST.
     *
     * @return bool
     */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Returns the request URI without query parameters.
     *
     * @return string
     */
    public function uri(): string
    {
        return strtok($this->server['REQUEST_URI'] ?? '/', '?');
    }

    /**
     * Determines whether the request is an AJAX request.
     *
     * @return bool
     */
    public function isAjax(): bool
    {
        return strtolower($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    /**
     * Retrieves an input value from POST or GET.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key]
            ?? $this->get[$key]
            ?? $default;
    }

    /**
     * Returns all input data (GET + POST).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->get, $this->post);
    }

    /**
     * Checks if an input key exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->post[$key]) || isset($this->get[$key]);
    }

    /**
     * Returns an uploaded file wrapper if present.
     *
     * @param string $key
     * @return UploadedFile|null
     */
    public function file(string $key): ?UploadedFile
    {
        if (!isset($this->files[$key]) || $this->files[$key]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return new UploadedFile($this->files[$key]);
    }

    /**
     * Returns all uploaded files.
     *
     * @return array<string, array>
     */
    public function files(): array
    {
        return $this->files;
    }
}
