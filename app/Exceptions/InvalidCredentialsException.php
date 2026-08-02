<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Symfony\Component\HttpFoundation\Response;

final class InvalidCredentialsException extends Exception
{
    public function __construct(string $message = 'Invalid credentials.')
    {
        parent::__construct($message, Response::HTTP_UNAUTHORIZED);
    }
}
