<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $exception instanceof ValidationException => $this->response(
                message: 'The given data was invalid.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                errors: $exception->errors(),
            ),
            $exception instanceof AuthenticationException => $this->response(
                message: 'Unauthenticated.',
                status: Response::HTTP_UNAUTHORIZED,
            ),
            $exception instanceof AuthorizationException => $this->response(
                message: $exception->getMessage() ?: 'This action is unauthorized.',
                status: Response::HTTP_FORBIDDEN,
            ),
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => $this->response(
                message: 'Resource not found.',
                status: Response::HTTP_NOT_FOUND,
            ),
            $exception instanceof HttpExceptionInterface => $this->response(
                message: $exception->getMessage() ?: Response::$statusTexts[$exception->getStatusCode()] ?? 'Error',
                status: $exception->getStatusCode(),
            ),
            default => $this->response(
                message: config('app.debug')
                    ? $exception->getMessage()
                    : 'An unexpected error occurred.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
                errors: config('app.debug') ? [
                    'exception' => $exception::class,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ] : null,
            ),
        };
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    private function response(
        string $message,
        int $status,
        ?array $errors = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
            'meta' => null,
        ], $status);
    }
}
