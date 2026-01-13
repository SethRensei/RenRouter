<?php
declare(strict_types=1);
namespace RenRouter;

use AltoRouter;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use InvalidArgumentException;

/**
 * Class Router
 *
 * Core routing service responsible for:
 *  - Registering HTTP routes
 *  - Dispatching incoming requests
 *  - Resolving controllers, callables or views
 *  - Rendering views with layout support
 *  - Generating URLs from named routes
 *  - Handling HTTP errors (404, 500)
 *
 * This class acts as the central infrastructure layer of the framework
 * and intentionally contains no business logic.
 *
 * @package RenRouter
 */
final class Router
{
    /**
     * Absolute path to the views directory.
     *
     * @var string
     */
    private string $viewsPath;

    /**
     * Internal router engine (AltoRouter).
     *
     * @var AltoRouter
     */
    private AltoRouter $router;

    /**
     * Optional PSR-3 logger.
     *
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * Router constructor.
     *
     * @param string $viewsPath Absolute path to the views directory
     * @param AltoRouter|null $router Custom router engine (optional)
     * @param LoggerInterface|null $logger PSR-3 logger (optional)
     *
     * @throws InvalidArgumentException If views path is invalid or unreadable
     */
    public function __construct(
        string $viewsPath,
        ?AltoRouter $router = null,
        ?LoggerInterface $logger = null
    ) {
        $this->viewsPath = rtrim($viewsPath, DIRECTORY_SEPARATOR);

        if (!is_dir($this->viewsPath) || !is_readable($this->viewsPath)) {
            throw new InvalidArgumentException(
                "Views path '{$this->viewsPath}' is not a readable directory."
            );
        }

        $this->router = $router ?? new AltoRouter();
        $this->logger = $logger;
    }

    /**
     * Registers an HTTP route.
     *
     * @param string $uri Route URI pattern
     * @param string|callable $target View name, callable or controller action
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string|null $name Route name (optional)
     *
     * @return self
     *
     * @throws InvalidArgumentException If HTTP method is empty
     */
    public function route(
        string $uri,
        string|callable $target,
        string $method = 'GET',
        ?string $name = null
    ): self {
        $method = strtoupper(trim($method));

        if ($method === '') {
            throw new InvalidArgumentException('HTTP method cannot be empty.');
        }

        $this->router->map($method, $uri, $target, $name);
        return $this;
    }

    /**
     * Dispatches the current HTTP request.
     *
     * Matches the incoming request against registered routes and
     * executes the corresponding handler or view.
     *
     * @return self
     */
    public function run(): self
    {
        $match = $this->router->match();

        if ($match === false) {
            $this->respondNotFound();
            return $this;
        }

        $target = $match['target'] ?? null;
        $params = $match['params'] ?? [];

        try {
            if (is_callable($target)) {
                $this->dispatchCallable($target, $params);
                return $this;
            }

            if (is_string($target)) {
                $this->renderView($target);
                return $this;
            }

            throw new RuntimeException('Invalid route target.');
        } catch (\Throwable $e) {
            $this->handleException($e);
        }

        return $this;
    }

    /**
     * Generates an absolute URL from a named route.
     *
     * @param string $name Route name
     * @param array $params Route parameters
     *
     * @return string
     *
     * @throws RuntimeException If base URL configuration is invalid
     */
    public function url(string $name, array $params = []): string
    {
        $baseUrl = $_ENV['APP_URL']
            ?? ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Invalid APP_URL configuration.');
        }

        $baseUrl = rtrim($baseUrl, '/');

        try {
            $path = $this->router->generate($name, $params);
        } catch (\Throwable $e) {
            $this->logger?->error('URL generation failed', [
                'name' => $name,
                'params' => $params,
                'exception' => $e,
            ]);
            return $baseUrl . '/';
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }

    /* =========================
       Internal dispatch helpers
       ========================= */

    /**
     * Dispatches a callable route handler.
     *
     * @param callable $handler
     * @param array $params Route parameters
     *
     * @return void
     */
    private function dispatchCallable(callable $handler, array $params): void
    {
        $result = $handler($this, $params);

        if ($result instanceof ResponseInterface) {
            echo (string) $result->getBody();
            return;
        }

        if (is_string($result)) {
            echo $result;
        }
    }

    /**
     * Renders a view with optional layout handling.
     *
     * @param string $view View name
     *
     * @return void
     */
    private function renderView(string $view): void
    {
        $viewFile = $this->resolveViewPath($view);
        $isAjax = $this->isAjaxRequest();

        // Inject router into view scope
        $router = $this;

        if ($isAjax) {
            $this->sendHeader('Content-Type', 'text/html; charset=UTF-8');
            require $viewFile;
            return;
        }

        ob_start();
        require $viewFile;
        $pg_content = (string) ob_get_clean();

        $baseFile = $this->viewsPath . DIRECTORY_SEPARATOR . 'base.php';
        if (!is_file($baseFile) || !is_readable($baseFile)) {
            throw new RuntimeException("Base layout not found.");
        }

        require $baseFile;
    }

    /**
     * Resolves and validates a view file path.
     *
     * Provides strong protection against directory traversal attacks.
     *
     * @param string $view View name
     *
     * @return string Absolute view file path
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    private function resolveViewPath(string $view): string
    {
        if (str_contains($view, "\0")) {
            throw new InvalidArgumentException('Invalid view name.');
        }

        $normalized = str_replace(['../', '..\\'], '', $view);
        $file = $this->viewsPath . DIRECTORY_SEPARATOR . $normalized . '.php';

        $realBase = realpath($this->viewsPath);
        $realFile = realpath($file);

        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
            throw new RuntimeException('View outside allowed directory.');
        }

        if (!is_file($realFile) || !is_readable($realFile)) {
            throw new RuntimeException('View file not readable.');
        }

        return $realFile;
    }

    /**
     * Determines whether the current request is an AJAX request.
     *
     * @return bool
     */
    private function isAjaxRequest(): bool
    {
        $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');

        return $requestedWith === 'xmlhttprequest'
            || str_contains($accept, 'application/json')
            || str_contains($accept, 'text/javascript');
    }

    /**
     * Sends a 404 Not Found response.
     *
     * @return void
     */
    private function respondNotFound(): void
    {
        http_response_code(404);
        $this->logger?->warning('Route not found', [
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
        ]);

        $fallback = $this->viewsPath . DIRECTORY_SEPARATOR . '404.php';
        if (is_file($fallback)) {
            require $fallback;
            return;
        }

        echo '404 Not Found';
    }

    /**
     * Handles internal exceptions and sends a 500 response.
     *
     * @param \Throwable $e
     *
     * @return void
     */
    private function handleException(\Throwable $e): void
    {
        $this->logger?->error('Router exception', ['exception' => $e]);

        http_response_code(500);
        $this->sendHeader('Content-Type', 'text/plain; charset=UTF-8');

        if ((bool) ini_get('display_errors')) {
            echo 'Internal Server Error: ' . $e->getMessage();
        } else {
            echo 'Internal Server Error';
        }
    }

    /**
     * Renders a view and injects data into its scope.
     *
     * @param string $view View name
     * @param array $data Variables available in the view
     *
     * @return void
     */
    public function render(string $view, array $data = []): void
    {
        $viewFile = $this->resolveViewPath($view);

        // Inject common objects
        $router = $this;

        // Inject user data safely
        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $pg_content = (string) ob_get_clean();

        $baseFile = $this->viewsPath . DIRECTORY_SEPARATOR . 'base.php';
        require $baseFile;
    }

    /**
     * Sends an HTTP header if headers are not already sent.
     *
     * @param string $name
     * @param string $value
     *
     * @return void
     */
    private function sendHeader(string $name, string $value): void
    {
        if (!headers_sent()) {
            header("$name: $value");
        }
    }

    /**
     * Generates an absolute URL for a public asset.
     *
     * @param string $path Asset relative path
     *
     * @return string
     */
    public function asset(string $path): string
    {
        $baseUrl = $_ENV['APP_URL'] ?? '';
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
