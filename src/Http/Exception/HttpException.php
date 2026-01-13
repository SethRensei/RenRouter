<?php

namespace RenRouter\Http\Exception;

use RuntimeException;

/**
 * Class HttpException
 *
 * Base exception for HTTP-related errors.
 * Stores an HTTP status code alongside the exception message.
 * 
 * @package RenRouter\Http\Exception
 */
abstract class HttpException extends RuntimeException
{
    /**
     * @param int    $statusCode HTTP status code associated with the exception.
     * @param string $message    Human-readable error message.
     */
    public function __construct(
        protected int $statusCode,
        string $message = ''
    ) {
        parent::__construct($message);
    }

    /**
     * Returns the HTTP status code.
     *
     * @return int The HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
