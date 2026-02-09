<?php
declare(strict_types=1);
namespace RenRouter\Security;

/**
 * Class Auth
 *
 * Provides authentication and authorization helper methods
 * based on the current user session.
 * 
 * @package RenRouter\Security
 */
final class Auth
{
    /**
     * Determines whether a user is currently authenticated.
     *
     * This method checks if the user data exists in the session.
     *
     * @return bool True if the user is authenticated, false otherwise.
     */
    public static function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Retrieves all roles assigned to the authenticated user.
     *
     * If no user is authenticated or no roles are defined,
     * an empty array is returned.
     *
     * @return array<string> List of user roles.
     */
    public static function roles(): array
    {
        return $_SESSION['user']['roles'] ?? [];
    }

    /**
     * Checks whether the authenticated user has a specific role.
     *
     * @param string $role The role to check.
     *
     * @return bool True if the user has the given role, false otherwise.
     */
    public static function hasRole(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }

    /**
     * Checks whether the authenticated user has at least one
     * role from a given list of required roles.
     *
     * @param array<string> $requiredRoles List of roles to check against.
     *
     * @return bool True if at least one role matches, false otherwise.
     */
    public static function hasAnyRole(array $requiredRoles): bool
    {
        return !empty(array_intersect(self::roles(), $requiredRoles));
    }
}
