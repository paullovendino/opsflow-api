<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\Api\V1\DepartmentResource;
use App\Http\Resources\Api\V1\JobTitleResource;
use App\Http\Resources\Api\V1\RoleResource;
use App\Models\Department;
use App\Services\Lookups\LookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function jobTitles(Request $request): JsonResponse
    {
        $departmentId = $request->filled('department_id')
            ? (int) $request->integer('department_id')
            : null;

        $includeId = $request->filled('include_id')
            ? (int) $request->integer('include_id')
            : null;

        return $this->successResponse(
            data: JobTitleResource::collection(
                $this->lookupService->jobTitles($departmentId, $includeId),
            ),
            message: 'Job titles retrieved successfully.',
        );
    }

    public function jobTitlesForDepartment(Request $request, Department $department): JsonResponse
    {
        $includeId = $request->filled('include_id')
            ? (int) $request->integer('include_id')
            : null;

        return $this->successResponse(
            data: JobTitleResource::collection(
                $this->lookupService->jobTitlesForDepartment($department, $includeId),
            ),
            message: 'Job titles retrieved successfully.',
        );
    }
}
