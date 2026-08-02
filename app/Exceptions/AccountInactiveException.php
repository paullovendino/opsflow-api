<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Symfony\Component\HttpFoundation\Response;

final class AccountInactiveException extends Exception
{
    public function __construct(string $message = 'Account is inactive.')
    {
        parent::__construct($message, Response::HTTP_FORBIDDEN);
    }
}
