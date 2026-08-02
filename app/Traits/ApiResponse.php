<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponse
{
    protected function successResponse(
        mixed $data = null,
        string $message = 'Request completed successfully.',
        int $status = Response::HTTP_OK,
        mixed $meta = null,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->resolveData($data),
            'errors' => null,
            'meta' => $meta,
        ], $status);
    }

    protected function errorResponse(
        string $message,
        int $status = Response::HTTP_BAD_REQUEST,
        mixed $errors = null,
        mixed $data = null,
        mixed $meta = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta,
        ], $status);
    }

    protected function paginatedResponse(
        LengthAwarePaginator $paginator,
        mixed $data = null,
        string $message = 'Request completed successfully.',
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        return $this->successResponse(
            data: $data ?? $paginator->items(),
            message: $message,
            status: $status,
            meta: [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        );
    }

    private function resolveData(mixed $data): mixed
    {
        if ($data instanceof JsonResource || $data instanceof ResourceCollection) {
            return $data->resolve();
        }

        return $data;
    }
}
