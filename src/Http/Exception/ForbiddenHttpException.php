<?php

namespace RenRouter\Http\Exception;

/**
 * Class ForbiddenHttpException
 *
 * Represents an HTTP 403 Forbidden error.
 * 
 * @package RenRouter\Http\Exception
 */
final class ForbiddenHttpException extends HttpException
{
    /**
     * @param string $message Error message describing the access restriction.
     */
    public function __construct(
        string $message = 'Access denied',
        private array $requiredRoles = []
        )
    {
        parent::__construct(403, $message);
    }

    public function getRequiredRoles(): array
    {
        return $this->requiredRoles;
    }
}
