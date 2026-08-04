<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Dashboard\ShowDashboardRequest;
use App\Http\Resources\Api\V1\DashboardResource;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends BaseApiController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function show(ShowDashboardRequest $request): JsonResponse
    {
        $this->authorize('viewDashboard');

        /** @var User $actor */
        $actor = $request->user();

        $summary = $this->dashboardService->summary(
            actor: $actor,
            recentLimit: $request->recentLimit(),
        );

        return $this->successResponse(
            data: new DashboardResource($summary),
            message: 'Dashboard retrieved successfully.',
        );
    }
}
