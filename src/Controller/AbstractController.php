<?php
declare(strict_types=1);
namespace RenRouter\Controller;

use RenRouter\Router;
use RenRouter\Security\Auth;
use RenRouter\Http\Exception\ForbiddenHttpException;
use RenRouter\Http\Exception\UnauthorizedHttpException;

abstract class AbstractController
{

    public function __construct(
        protected Router $router
    ) { }
    
    /**
     * Require authentication for the current action.
     */
    protected function requireAuth(): void
    {
        if (Auth::check()) {
            return;
        }

        $routeName = $this->router->getSecurityRouteName();

        if (!$this->router->hasRoute($routeName)) {
            throw new UnauthorizedHttpException(
                'Access denied. Authentication required.'
            );
        }

        throw new UnauthorizedHttpException(
            'Authentication required.',
            redirectTo: $this->router->url($routeName)
        );
    }

    /**
     * Require at least one role.
     */
    protected function requireRole(string|array $roles): void
    {
        $this->requireAuth();
        // $_SESSION['required_roles'] = (array) $roles; // Store required roles in session for error page access
        if (!Auth::hasAnyRole((array) $roles)) {
            throw new ForbiddenHttpException(
                requiredRoles: (array) $roles
            );
        }
    }
}
