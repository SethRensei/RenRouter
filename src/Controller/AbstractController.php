<?php
declare(strict_types=1);
namespace RenRouter\Controller;

use RenRouter\Router;
use RenRouter\Security\Auth;
use RenRouter\Http\Exception\ForbiddenHttpException;
use RenRouter\Http\Exception\UnauthorizedHttpException;

abstract class AbstractController
{
    /**
     * Require authentication for the current action.
     */
    protected function requireAuth(Router $router): void
    {
        if (Auth::check()) {
            return;
        }

        $routeName = $router->getSecurityRouteName();

        if (!$router->hasRoute($routeName)) {
            throw new UnauthorizedHttpException(
                'Access denied. Authentication required.'
            );
        }

        header('Location: ' . $router->url($routeName));
        exit;
    }

    /**
     * Require at least one role.
     */
    protected function requireRole(Router $router, string|array $roles): void
    {
        $this->requireAuth($router);

        if (!Auth::hasAnyRole((array) $roles)) {
            throw new ForbiddenHttpException();
        }
    }
}
