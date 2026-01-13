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
    public function __construct(string $message = 'Access denied')
    {
        parent::__construct(403, $message);
    }
}
