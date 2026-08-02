<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;

class HealthController extends BaseApiController
{
    public function __invoke(): JsonResponse
    {
        return $this->successResponse(
            data: [
                'status' => 'ok',
                'service' => 'opsflow-api',
                'timestamp' => now()->toIso8601String(),
            ],
        );
    }
}
