<?php
declare(strict_types=1);

namespace RenRouter\Security;

/**
 * Class Auth
 *
 * Stateless authentication and authorization helper.
 * All state is read from and written to the current PHP session.
 *
 * Conventions:
 *  - The authenticated user is stored as an array under $_SESSION['user']
 *  - Roles are stored under $_SESSION['user']['roles'] as a list of strings
 *
 * @package RenRouter\Security
 */
final class Auth
{
    /** Session key used to store the authenticated user. */
    private const SESSION_KEY = 'user';

    // ── Read ─────────────────────────────────────────────────

    /**
     * Returns true if a user is currently authenticated.
     */
    public static function check(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Returns the authenticated user array, or null if not authenticated.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /**
     * Returns a single field from the authenticated user.
     *
     * ```php
     * Auth::get('name');   // 'Alice'
     * Auth::get('email');  // 'alice@example.com'
     * Auth::get('missing', 'default');
     * ```
     *
     * @param string $key     Field name inside the user array
     * @param mixed  $default Returned when the field is absent
     *
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[self::SESSION_KEY][$key] ?? $default;
    }

    /**
     * Returns the authenticated user's identifier (id field), or null.
     *
     * @return int|string|null
     */
    public static function id(): int|string|null
    {
        $id = $_SESSION[self::SESSION_KEY]['id'] ?? null;

        return is_int($id) || is_string($id) ? $id : null;
    }

    // ── Roles ─────────────────────────────────────────────────

    /**
     * Returns all roles assigned to the authenticated user.
     * Returns an empty array when not authenticated or roles are absent.
     *
     * @return string[]
     */
    public static function roles(): array
    {
        $roles = $_SESSION[self::SESSION_KEY]['roles'] ?? [];

        return is_array($roles) ? $roles : [];
    }

    /**
     * Returns true if the authenticated user has the given role.
     */
    public static function hasRole(string $role): bool
    {
        return in_array($role, self::roles(), strict: true);
    }

    /**
     * Returns true if the authenticated user has at least one of the required roles.
     *
     * @param string[] $requiredRoles
     */
    public static function hasAnyRole(array $requiredRoles): bool
    {
        if (empty($requiredRoles)) {
            return false;
        }

        return !empty(array_intersect(self::roles(), $requiredRoles));
    }

    /**
     * Returns true if the authenticated user has ALL of the required roles.
     *
     * @param string[] $requiredRoles
     */
    public static function hasAllRoles(array $requiredRoles): bool
    {
        if (empty($requiredRoles)) {
            return false;
        }

        return empty(array_diff($requiredRoles, self::roles()));
    }

    // ── Write ─────────────────────────────────────────────────

    /**
     * Stores the user in the session, marking them as authenticated.
     *
     * Regenerates the session ID to prevent session fixation attacks.
     * Should be called immediately after successful credential verification.
     *
     * ```php
     * Auth::login([
     *     'id'    => $user->id,
     *     'name'  => $user->name,
     *     'email' => $user->email,
     *     'roles' => $user->roles,
     * ]);
     * ```
     *
     * @param array<string, mixed> $userData
     *
     * @throws \InvalidArgumentException If userData does not contain an 'id' key.
     */
    public static function login(array $userData): void
    {
        if (!isset($userData['id'])) {
            throw new \InvalidArgumentException(
                'Auth::login() requires an "id" key in $userData.'
            );
        }

        self::ensureSession();
        session_regenerate_id(delete_old_session: true);

        $_SESSION[self::SESSION_KEY] = $userData;
    }

    /**
     * Destroys the session and clears the authenticated user.
     *
     * Follows the PHP recommended logout sequence:
     * clear session data → delete cookie → destroy session.
     */
    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        // Remove the session cookie from the browser
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
    }

    /**
     * Refreshes the session ID without destroying session data.
     *
     * Call periodically (e.g. every N minutes) to reduce session hijacking risk.
     */
    public static function refreshSession(): void
    {
        self::ensureSession();
        session_regenerate_id(delete_old_session: true);
    }

    // ── Internal ──────────────────────────────────────────────

    /**
     * Starts the session if not already active.
     */
    public static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}