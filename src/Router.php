<?php
declare(strict_types=1);

namespace RenRouter;

use AltoRouter;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use RenRouter\Http\Exception\HttpException;
use RenRouter\Http\Exception\NotFoundHttpException;
use RenRouter\Http\Exception\ForbiddenHttpException;
use RenRouter\Http\Exception\UnauthorizedHttpException;
use RenRouter\Security\Auth;
use RenRouter\Template\TemplateEngineInterface;
use RenRouter\Template\PhpTemplateEngine;
use RuntimeException;
use InvalidArgumentException;

/**
 * Class Router
 *
 * Core routing service responsible for:
 *  - Registering HTTP routes
 *  - Dispatching incoming requests
 *  - Resolving controllers, callables or views
 *  - Rendering views with layout support (PHP or Twig)
 *  - Generating URLs from named routes
 *  - URL extension spoofing (e.g. .html, .php, .aspx) for security obfuscation
 *  - Handling HTTP errors (401, 403, 404, 500)
 *
 * @package RenRouter
 */
final class Router
{
    /** @var string Cached real path to views directory */
    private readonly string $viewsPath;

    /** @var string Security route name for login redirect */
    private readonly string $securityRouteName;

    /** @var AltoRouter AltoRouter instance */
    private readonly AltoRouter $router;

    /** @var ?LoggerInterface PSR-3 logger */
    private readonly ?LoggerInterface $logger;

    /** @var TemplateEngineInterface Template engine (PHP or Twig) */
    private readonly TemplateEngineInterface $templateEngine;

    /**
     * URL extension to append on generated URLs for security obfuscation.
     * e.g. '.html' => /contact.html, '' => /contact
     * The router strips this suffix before matching internally.
     */
    private readonly string $urlExtension;

    /** @var array<int, string|null> Named error routes per HTTP status code */
    private array $errorRoutes = [
        401 => null,
        403 => null,
        404 => null,
        500 => null,
    ];

    /** @var bool Whether we're in development mode (cached) */
    private readonly bool $isDev;

    /** @var string|null Cached base URL */
    private ?string $cachedBaseUrl = null;

    /**
     * Router constructor.
     *
     * @param string                   $viewsPath          Absolute path to the views directory
     * @param AltoRouter|null          $router             Custom router engine (optional)
     * @param LoggerInterface|null     $logger             PSR-3 logger (optional)
     * @param string|null              $securityRouteName  Named route for login redirect
     * @param TemplateEngineInterface|null $templateEngine Template engine override (e.g. TwigEngine)
     * @param string                   $urlExtension       Fake URL suffix to append (e.g. '.html', '.aspx')
     *
     * @throws InvalidArgumentException If views path is invalid or unreadable
     */
    public function __construct(
        string $viewsPath,
        ?AltoRouter $router = null,
        string $urlExtension = '',
        ?LoggerInterface $logger = null,
        ?string $securityRouteName = null,
        ?TemplateEngineInterface $templateEngine = null
    ) {
        $realPath = realpath(rtrim($viewsPath, DIRECTORY_SEPARATOR));

        if ($realPath === false || !is_dir($realPath) || !is_readable($realPath)) {
            throw new InvalidArgumentException(
                "Views path '{$viewsPath}' is not a readable directory."
            );
        }

        $this->viewsPath = $realPath;
        $this->router = $router ?? new AltoRouter();
        $this->urlExtension = $this->normalizeExtension($urlExtension);
        $this->logger = $logger;
        $this->securityRouteName = $securityRouteName ?? 'security.login';
        $this->isDev = ($_ENV['APP_ENV'] ?? 'PROD') === 'DEV';
        $this->templateEngine = $templateEngine ?? new PhpTemplateEngine($this->viewsPath);

        // Pre-configure AltoRouter base path once
        $basePath = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?? '';
        if ($basePath && $basePath !== '/') {
            $this->router->setBasePath(rtrim($basePath, '/'));
        }
    }

    /* =========================================================
       Route registration
       ========================================================= */

    /**
     * Registers an HTTP route.
     *
     * @param string          $uri     Route URI pattern (without the fake extension)
     * @param string|callable $target  View name, callable, or "Controller@method"
     * @param string          $method  HTTP method (GET, POST, PUT, DELETE, PATCH…)
     * @param string|null     $name    Named route identifier
     * @param array|null      $options Authorization options (auth, roles)
     *
     * @return self
     * @throws InvalidArgumentException
     */
    public function route(
        string $uri,
        string|callable $target,
        string $method = 'GET',
        ?string $name = null,
        ?array $options = null
    ): self {
        $method = strtoupper(trim($method));
        if ($method === '') {
            throw new InvalidArgumentException('HTTP method cannot be empty.');
        }

        $this->router->map(
            $method,
            $uri,
            ['target' => $target, 'options' => $options ?? []],
            $name
        );

        return $this;
    }

    /**
     * Shortcut: registers a GET route.
     */
    public function get(string $uri, string|callable $target, ?string $name = null, ?array $options = null): self
    {
        return $this->route($uri, $target, 'GET', $name, $options);
    }

    /**
     * Shortcut: registers a POST route.
     */
    public function post(string $uri, string|callable $target, ?string $name = null, ?array $options = null): self
    {
        return $this->route($uri, $target, 'POST', $name, $options);
    }

    /**
     * Shortcut: group of routes sharing common options/prefix.
     *
     * @param array    $options Common options (auth, roles, prefix, …)
     * @param callable $callback fn(Router $router): void
     */
    public function group(array $options, callable $callback): self
    {
        $callback($this, $options);
        return $this;
    }

    /* =========================================================
       Dispatch
       ========================================================= */

    /**
     * Dispatches the current HTTP request.
     *
     * Strips the fake URL extension before matching,
     * then executes the matched handler.
     *
     * @return self
     */
    public function run(): self
    {
        // Rewrite REQUEST_URI to strip the fake extension before AltoRouter sees it
        if ($this->urlExtension !== '') {
            $_SERVER['REQUEST_URI'] = $this->stripExtensionFromUri(
                $_SERVER['REQUEST_URI'] ?? '/'
            );
        }

        $match = $this->router->match();

        try {
            if ($match === false) {
                throw new NotFoundHttpException();
            }

            ['target' => $target, 'options' => $options] = $match['target'];
            $params = $match['params'] ?? [];

            $this->authorize($options);
            $this->dispatch($target, $params);

        } catch (\Throwable $e) {
            $this->handleException($e);
        }

        return $this;
    }

    /* =========================================================
       URL generation
       ========================================================= */

    /**
     * Generates an absolute URL from a named route,
     * appending the fake URL extension when configured.
     *
     * @param string $name   Named route
     * @param array  $params Route parameters
     *
     * @return string  e.g. https://example.com/contact.html
     * @throws RuntimeException
     */
    public function url(string $name, array $params = []): string
    {
        try {
            $path = $this->router->generate($name, $params);
        } catch (\Throwable $e) {
            $this->logger?->error('URL generation failed', [
                'route' => $name,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Route '{$name}' not found.", 0, $e);
        }

        // Append fake extension before query string / fragment
        if ($this->urlExtension !== '') {
            $path = $this->appendExtensionToPath($path);
        }

        return $this->getBaseUrl() . '/' . ltrim($path, '/');
    }

    /**
     * Generates a relative path (no base URL) for a named route.
     * Useful for href attributes when APP_URL is not set.
     */
    public function path(string $name, array $params = []): string
    {
        try {
            $path = $this->router->generate($name, $params);
        } catch (\Throwable $e) {
            throw new RuntimeException("Route '{$name}' not found.", 0, $e);
        }

        return $this->urlExtension !== ''
            ? $this->appendExtensionToPath($path)
            : $path;
    }

    /**
     * Returns the fake URL extension configured at init.
     * e.g. '.html'
     */
    public function getUrlExtension(): string
    {
        return $this->urlExtension;
    }

    /* =========================================================
       Redirect helpers
       ========================================================= */

    /**
     * Redirects to a named route.
     */
    public function redirect(string $routeName, array $params = [], int $status = 302): void
    {
        $this->redirectUrl($this->url($routeName, $params), $status);
    }

    /**
     * Redirects to a raw URL.
     */
    public function redirectUrl(string $url, int $status = 302): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . $url, true, $status);
        }
        exit;
    }

    /* =========================================================
       Rendering
       ========================================================= */

    /**
     * Renders a view through the configured template engine.
     *
     * @param string $view View name / path (without extension)
     * @param array  $data Variables injected into the template
     */
    public function render(string $view, array $data = []): void
    {
        $data['router'] = $this;
        echo $this->templateEngine->render($view, $data);
    }

    /* =========================================================
       Asset helper
       ========================================================= */

    /**
     * Generates an absolute URL for a public asset.
     */
    public function asset(string $path): string
    {
        return rtrim($this->getBaseUrl(), '/') . '/' . ltrim($path, '/');
    }

    /* =========================================================
       Route existence check
       ========================================================= */

    /**
     * Checks whether a named route is registered.
     */
    public function hasRoute(string $name): bool
    {
        try {
            $this->router->generate($name);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /* =========================================================
       Error route configuration
       ========================================================= */

    /**
     * Maps an HTTP status code to a named route for error handling.
     */
    public function setErrorRoute(int $code, string $routeName): self
    {
        $this->errorRoutes[$code] = $routeName;
        return $this;
    }

    /* =========================================================
       Accessors
       ========================================================= */

    public function getSecurityRouteName(): string
    {
        return $this->securityRouteName;
    }

    /* =========================================================
       Internal helpers
       ========================================================= */

    /**
     * Normalizes the URL extension: ensures it starts with a dot or is empty.
     * Also validates that the extension contains only safe characters.
     */
    private function normalizeExtension(string $ext): string
    {
        if ($ext === '') {
            return '';
        }

        // Strip leading dots for normalization
        $clean = ltrim($ext, '.');

        // Only allow alphanumeric extension names (e.g. html, php, aspx, jsp)
        if (!preg_match('/^[a-z0-9]{1,10}$/i', $clean)) {
            throw new InvalidArgumentException(
                "Invalid URL extension '{$ext}'. Only alphanumeric extensions are allowed."
            );
        }

        return '.' . strtolower($clean);
    }

    /**
     * Strips the fake URL extension from a URI, preserving query string.
     *
     * /contact.html?ref=1 => /contact?ref=1
     */
    private function stripExtensionFromUri(string $uri): string
    {
        // Separate path from query string
        $qPos = strpos($uri, '?');
        [$path, $query] = $qPos !== false
            ? [substr($uri, 0, $qPos), substr($uri, $qPos)]
            : [$uri, ''];

        $ext = $this->urlExtension;
        $len = strlen($ext);

        if ($len > 0 && str_ends_with($path, $ext)) {
            $path = substr($path, 0, -$len);
        }

        return $path . $query;
    }

    /**
     * Appends the fake extension to a path, before query string / fragment.
     *
     * /contact?ref=1 => /contact.html?ref=1
     */
    private function appendExtensionToPath(string $path): string
    {
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            return substr($path, 0, $qPos) . $this->urlExtension . substr($path, $qPos);
        }

        $fPos = strpos($path, '#');
        if ($fPos !== false) {
            return substr($path, 0, $fPos) . $this->urlExtension . substr($path, $fPos);
        }

        return $path . $this->urlExtension;
    }

    /**
     * Returns the cached base URL.
     */
    private function getBaseUrl(): string
    {
        if ($this->cachedBaseUrl === null) {
            $url = $_ENV['APP_URL']
                ?? (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Invalid APP_URL configuration.');
            }

            $this->cachedBaseUrl = rtrim($url, '/');
        }

        return $this->cachedBaseUrl;
    }

    /**
     * Dispatches a matched route target (callable or string view/controller).
     */
    private function dispatch(string|callable $target, array $params): void
    {
        if (is_callable($target)) {
            $this->dispatchCallable($target, $params);
            return;
        }

        // Support "Controller@method" syntax
        if (is_string($target) && str_contains($target, '@')) {
            $this->dispatchController($target, $params);
            return;
        }

        if (is_string($target)) {
            $this->render($target);
            return;
        }

        throw new RuntimeException('Invalid route target: ' . print_r($target, true));
    }

    /**
     * Invokes a callable handler.
     */
    private function dispatchCallable(callable $handler, array $params): void
    {
        $args = $this->resolveCallableArgs($handler, $params);
        $result = $handler(...$args);

        if ($result instanceof ResponseInterface) {
            echo (string) $result->getBody();
            return;
        }

        if (is_string($result)) {
            echo $result;
        }
    }

    /**
     * Resolves and calls a "ControllerClass@method" string.
     */
    private function dispatchController(string $target, array $params): void
    {
        [$class, $method] = explode('@', $target, 2);

        if (!class_exists($class)) {
            throw new RuntimeException("Controller class '{$class}' not found.");
        }

        $controller = new $class($this);

        if (!method_exists($controller, $method)) {
            throw new RuntimeException("Method '{$method}' not found on '{$class}'.");
        }

        $result = $controller->{$method}($params);

        if ($result instanceof ResponseInterface) {
            echo (string) $result->getBody();
        } elseif (is_string($result)) {
            echo $result;
        }
    }

    /**
     * Resolves the argument list to pass to a callable handler.
     *
     * Rules (first parameter of the callable drives the decision):
     *
     *   1. No parameters            → []
     *   2. First param type = Router → [$this, $params]   (closure / static callback)
     *   3. First param type = array  → [$params]           (controller method, Router already injected)
     *   4. Untyped / other           → [$params]           (safe default: just params)
     *
     * @param callable $handler
     * @param array    $params  Route parameters from AltoRouter
     * @return array            Arguments to spread into $handler(...$args)
     */
    private function resolveCallableArgs(callable $handler, array $params): array
    {
        try {
            if (is_array($handler)) {
                $ref = new \ReflectionMethod($handler[0], $handler[1]);
            } elseif (is_object($handler) && !($handler instanceof \Closure)) {
                $ref = new \ReflectionMethod($handler, '__invoke');
            } else {
                $ref = new \ReflectionFunction(\Closure::fromCallable($handler));
            }

            $refParams = $ref->getParameters();

            if (empty($refParams)) {
                return [];
            }

            $firstType = $refParams[0]->getType();
            $firstName = $firstType instanceof \ReflectionNamedType
                ? $firstType->getName()
                : null;

            // Explicit Router as first arg → pass Router + params (closure style)
            if ($firstName === self::class || $firstName === Router::class) {
                return [$this, $params];
            }

            // array or untyped → params only (controller method style)
            return [$params];

        } catch (\ReflectionException) {
            // Fallback: params only — avoids crashing on edge-case callables
            return [$params];
        }
    }

    /**
     * Authorizes the current request against route options.
     *
     * @throws UnauthorizedHttpException
     * @throws ForbiddenHttpException
     */
    private function authorize(array $options): void
    {
        if (($options['auth'] ?? false) === true && !Auth::check()) {
            if ($this->hasRoute($this->securityRouteName)) {
                $this->redirectUrl($this->url($this->securityRouteName));
            }
            throw new UnauthorizedHttpException('Authentication required.');
        }

        if (!empty($options['roles'])) {
            $required = (array) $options['roles'];
            if (!Auth::hasAnyRole($required)) {
                throw new ForbiddenHttpException(
                    'Access denied. Insufficient permissions.',
                    requiredRoles: $required
                );
            }
        }
    }

    /**
     * Centralized exception handler for all routing errors.
     */
    private function handleException(\Throwable $e): void
    {
        $this->logger?->error('Router exception', [
            'exception' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $code = ($e instanceof HttpException) ? $e->getStatusCode() : 500;

        if (!headers_sent()) {
            http_response_code($code);
        }

        // DEV mode: show raw error
        if ($this->isDev) {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo sprintf(
                "[%s] %s\n\n%s",
                get_class($e),
                $e->getMessage(),
                $e->getTraceAsString()
            );
            return;
        }

        // Try named error route
        $errorRouteName = $this->errorRoutes[$code] ?? null;
        if ($errorRouteName && $this->hasRoute($errorRouteName)) {
            try {
                $this->redirect($errorRouteName);
                return;
            } catch (\Throwable) {
                // fall through
            }
        }

        // Try error view file: views/errors/{code}.php or errors/{code}.twig
        try {
            $this->render("errors/{$code}", ['exception' => $e, 'code' => $code]);
            return;
        } catch (\Throwable) {
            // fall through
        }

        // Fallback to home route
        try {
            if ($this->hasRoute('home.app')) {
                $this->redirect('home.app');
                return;
            }
            $this->redirectUrl('/');
        } catch (\Throwable) {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo htmlspecialchars(
                $e instanceof HttpException ? $e->getMessage() : 'Internal Server Error',
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }
    }

    /**
     * Determines whether the request is an AJAX/JSON request.
     */
    private function isAjaxRequest(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }
}