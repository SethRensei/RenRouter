<?php
declare(strict_types=1);

namespace RenRouter\Controller;

use RenRouter\Router;
use RenRouter\Security\Auth;
use RenRouter\Http\Exception\ForbiddenHttpException;
use RenRouter\Http\Exception\UnauthorizedHttpException;
use RenRouter\Http\Exception\NotFoundHttpException;

/**
 * Abstract base controller for RenRouter.
 *
 * Provides a clean, expressive API for common controller actions:
 *   - Security guards (auth, roles)        → requireAuth(), requireRole(), denyUnless()
 *   - View rendering                       → render(), renderPartial()
 *   - HTTP redirects                       → redirect(), redirectToRoute()
 *   - JSON responses                       → json(), jsonError()
 *   - Flash messages                       → flash(), flashSuccess(), flashError()
 *   - Request helpers                      → input(), isMethod(), isAjax()
 *   - Not-found shorthand                  → notFound()
 *
 * The Router is injected per-action (not stored as a property) to keep
 * controllers stateless and testable without mocking constructor deps.
 *
 * Usage:
 * ```php
 * class UserController extends AbstractController
 * {
 *     public function show(array $params): void
 *     {
 *         $this->requireAuth($this->router);
 *         $this->render($this->router, 'users/show', ['id' => $params['id']]);
 *     }
 * }
 * ```
 *
 * Or with the Router injected at construction for less boilerplate:
 * ```php
 * class UserController extends AbstractController
 * {
 *     public function __construct(Router $router) {
 *         parent::__construct($router);
 *     }
 *
 *     public function show(array $params): void
 *     {
 *         $this->requireAuth();
 *         $this->render('users/show', ['id' => $params['id']]);
 *     }
 * }
 * ```
 *
 * @package RenRouter\Controller
 */
abstract class AbstractController
{
    /**
     * Optional Router stored at construction time.
     * When set, all helper methods accept it as optional (cleaner call sites).
     */
    private ?Router $router;

    /**
     * @param Router|null $router Inject once at construction for less boilerplate,
     *                            or pass per-method for maximum testability.
     */
    public function __construct(?Router $router = null)
    {
        $this->router = $router;
    }

    /* =========================================================
       Security guards
       ========================================================= */

    /**
     * Requires the current user to be authenticated.
     *
     * If not authenticated, redirects to the security route when it exists,
     * or throws UnauthorizedHttpException as fallback.
     *
     * @param Router|null $router Override the constructor-injected router.
     *
     * @throws UnauthorizedHttpException
     */
    protected function requireAuth(?Router $router = null): void
    {
        if (Auth::check()) {
            return;
        }

        $r         = $this->resolveRouter($router);
        $routeName = $r->getSecurityRouteName();

        if (!$r->hasRoute($routeName)) {
            throw new UnauthorizedHttpException('Access denied. Authentication required.');
        }

        throw new UnauthorizedHttpException(
            'Authentication required.',
            redirectTo: $r->url($routeName)
        );
    }

    /**
     * Requires the current user to hold at least one of the given roles.
     *
     * Always verifies authentication first; throws ForbiddenHttpException
     * if authenticated but without the required role.
     *
     * @param string|string[] $roles   One or more required role strings.
     * @param Router|null     $router  Override the constructor-injected router.
     *
     * @throws UnauthorizedHttpException If not authenticated.
     * @throws ForbiddenHttpException    If authenticated but lacking the role.
     */
    protected function requireRole(string|array $roles, ?Router $router = null): void
    {
        $this->requireAuth($router);

        $roles = (array) $roles;

        if (!Auth::hasAnyRole($roles)) {
            throw new ForbiddenHttpException(requiredRoles: $roles);
        }
    }

    /**
     * Throws ForbiddenHttpException if the given condition is false.
     *
     * Useful for object-level authorization (ownership checks, etc.):
     * ```php
     * $this->denyUnless($post->authorId === Auth::id(), 'Not your post.');
     * ```
     *
     * @param bool   $condition  Must be true to continue.
     * @param string $message    Custom error message.
     *
     * @throws ForbiddenHttpException
     */
    protected function denyUnless(bool $condition, string $message = 'Access denied.'): void
    {
        if (!$condition) {
            throw new ForbiddenHttpException(message: $message);
        }
    }

    /**
     * Shorthand for throwing a 404 Not Found exception.
     *
     * ```php
     * $user = UserRepository::find($params['id'])
     *     ?? $this->notFound("User #{$params['id']} not found.");
     * ```
     *
     * @throws NotFoundHttpException — always.
     * @return never
     */
    protected function notFound(string $message = 'Resource not found.'): never
    {
        throw new NotFoundHttpException($message);
    }

    /* =========================================================
       Rendering
       ========================================================= */

    /**
     * Renders a full view through the router's template engine.
     *
     * @param string               $view   View name (e.g. 'users/show', 'home/index')
     * @param array<string, mixed> $data   Variables passed to the template
     * @param Router|null          $router Override the constructor-injected router
     */
    protected function render(string $view, array $data = [], ?Router $router = null): void
    {
        $this->resolveRouter($router)->render($view, $data);
    }

    /**
     * Renders a partial view and returns its output as a string.
     *
     * Useful for AJAX fragment updates or email bodies.
     *
     * @param string               $view
     * @param array<string, mixed> $data
     * @param Router|null          $router
     *
     * @return string Rendered HTML fragment
     */
    protected function renderPartial(string $view, array $data = [], ?Router $router = null): string
    {
        ob_start();
        $this->render($view, $data, $router);
        return (string) ob_get_clean();
    }

    /* =========================================================
       Redirects
       ========================================================= */

    /**
     * Redirects to a named route.
     *
     * @param string              $routeName Named route identifier
     * @param array<string,mixed> $params    Route parameters
     * @param int                 $status    HTTP status code (301, 302, 303…)
     * @param Router|null         $router    Override the constructor-injected router
     */
    protected function redirectToRoute(
        string $routeName,
        array $params = [],
        int $status = 302,
        ?Router $router = null
    ): void {
        $this->resolveRouter($router)->redirect($routeName, $params, $status);
    }

    /**
     * Redirects to an arbitrary URL.
     *
     * @param string      $url    Absolute or relative URL
     * @param int         $status HTTP status code
     * @param Router|null $router Override the constructor-injected router
     */
    protected function redirect(string $url, int $status = 302, ?Router $router = null): void
    {
        $this->resolveRouter($router)->redirectUrl($url, $status);
    }

    /* =========================================================
       JSON responses
       ========================================================= */

    /**
     * Sends a JSON response and terminates the script.
     *
     * Sets Content-Type: application/json and the given status code,
     * then encodes $data with safe unicode/slash flags.
     *
     * @param mixed $data       Any JSON-serializable value
     * @param int   $status     HTTP status code (default 200)
     * @param int   $flags      json_encode flags
     */
    protected function json(
        mixed $data,
        int $status = 200,
        int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ): never {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode($data, $flags);
        exit;
    }

    /**
     * Sends a standardized JSON error response.
     *
     * Response shape:
     * ```json
     * { "error": true, "message": "...", "code": 422 }
     * ```
     *
     * @param string $message Human-readable error description
     * @param int    $status  HTTP status code (default 400)
     * @param array  $extra   Additional fields to merge into the response body
     */
    protected function jsonError(string $message, int $status = 400, array $extra = []): never
    {
        $this->json(
            array_merge(['error' => true, 'message' => $message, 'code' => $status], $extra),
            $status
        );
    }

    /* =========================================================
       Flash messages
       ========================================================= */

    /**
     * Stores a flash message in the session for the next request.
     *
     * Starts the session if not already started.
     *
     * @param string $type    Semantic type: 'success', 'error', 'info', 'warning'
     * @param string $message Human-readable text
     */
    protected function flash(string $type, string $message): void
    {
        Auth::ensureSession();
        $_SESSION['_flash'][$type][] = $message;
    }

    /** Shorthand for flash('success', ...) */
    protected function flashSuccess(string $message): void
    {
        $this->flash('success', $message);
    }

    /** Shorthand for flash('error', ...) */
    protected function flashError(string $message): void
    {
        $this->flash('error', $message);
    }

    /** Shorthand for flash('info', ...) */
    protected function flashInfo(string $message): void
    {
        $this->flash('info', $message);
    }

    /**
     * Retrieves and clears flash messages of a given type.
     *
     * @param string $type 'success' | 'error' | 'info' | 'warning'
     * @return string[]
     */
    protected function getFlash(string $type): array
    {
        Auth::ensureSession();
        $messages = $_SESSION['_flash'][$type] ?? [];
        unset($_SESSION['_flash'][$type]);
        return $messages;
    }

    /**
     * Retrieves and clears all flash messages, grouped by type.
     *
     * @return array<string, string[]>
     */
    protected function getAllFlash(): array
    {
        Auth::ensureSession();
        $all = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $all;
    }

    /* =========================================================
       Request helpers
       ========================================================= */

    /**
     * Returns a sanitized value from $_POST, $_GET, or both.
     *
     * @param string      $key      Field name
     * @param mixed       $default  Fallback when key is absent
     * @param string      $from     'post' | 'get' | 'any'
     *
     * @return mixed
     */
    protected function input(string $key, mixed $default = null, string $from = 'any'): mixed
    {
        return match ($from) {
            'post'  => $_POST[$key] ?? $default,
            'get'   => $_GET[$key]  ?? $default,
            default => $_POST[$key] ?? $_GET[$key] ?? $default,
        };
    }

    /**
     * Returns all POST data, optionally filtered to the given keys.
     *
     * @param string[] $only Keys to keep (empty = all)
     * @return array<string, mixed>
     */
    protected function postData(array $only = []): array
    {
        $data = $_POST;
        return $only ? array_intersect_key($data, array_flip($only)) : $data;
    }

    /**
     * Checks if the current request uses the given HTTP method.
     *
     * @param string $method Case-insensitive: 'GET', 'POST', 'PUT'…
     */
    protected function isMethod(string $method): bool
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === strtoupper($method);
    }

    /**
     * Returns true if the request was made via XMLHttpRequest or accepts JSON.
     */
    protected function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /* =========================================================
       Internal helpers
       ========================================================= */

    /**
     * Resolves the router: uses the method-level override if provided,
     * falls back to the constructor-injected instance, or throws if neither exists.
     *
     * @throws \LogicException If no router is available.
     */
    private function resolveRouter(?Router $router): Router
    {
        $resolved = $router ?? $this->router;

        if ($resolved === null) {
            throw new \LogicException(
                static::class . ' requires a Router instance. '
                . 'Either inject it at construction via parent::__construct($router) '
                . 'or pass it as a method argument.'
            );
        }

        return $resolved;
    }
}