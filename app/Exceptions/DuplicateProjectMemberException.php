<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class DuplicateProjectMemberException extends Exception implements HttpExceptionInterface
{
    public function __construct(string $message = 'User is already a member of this project.')
    {
        parent::__construct($message, Response::HTTP_CONFLICT);
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_CONFLICT;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
