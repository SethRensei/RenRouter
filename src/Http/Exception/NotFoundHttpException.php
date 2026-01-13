<?php

namespace RenRouter\Http\Exception;


/**
 * Class NotFoundHttpException
 *
 * Represents an HTTP 404 Not Found error.
 * 
 * @package RenRouter\Http\Exception
 */
final class NotFoundHttpException extends HttpException
{
    /**
     * @param string $message Error message describing the missing resource.
     */
    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct(404, $message);
    }
}
