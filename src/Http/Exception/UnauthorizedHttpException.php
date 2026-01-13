<?php

namespace RenRouter\Http\Exception;

/**
 * Class UnauthorizedHttpException
 *
 * Represents an HTTP 401 Unauthorized error.
 * 
 * @package RenRouter\Http\Exception
 */
final class UnauthorizedHttpException extends HttpException
{
    /**
     * @param string $message Error message indicating missing authentication.
     */
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct(401, $message);
    }
}
