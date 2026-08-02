<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\Api\V1\DepartmentResource;
use App\Http\Resources\Api\V1\JobTitleResource;
use App\Http\Resources\Api\V1\RoleResource;
use App\Services\Lookups\LookupService;
use Illuminate\Http\JsonResponse;

class LookupController extends BaseApiController
{
    public function __construct(
        private readonly LookupService $lookupService,
    ) {}

    public function roles(): JsonResponse
    {
        return $this->successResponse(
            data: RoleResource::collection($this->lookupService->roles()),
            message: 'Roles retrieved successfully.',
        );
    }

    public function departments(): JsonResponse
    {
        return $this->successResponse(
            data: DepartmentResource::collection($this->lookupService->departments()),
            message: 'Departments retrieved successfully.',
        );
    }

    public function jobTitles(): JsonResponse
    {
        return $this->successResponse(
            data: JobTitleResource::collection($this->lookupService->jobTitles()),
            message: 'Job titles retrieved successfully.',
        );
    }
}
