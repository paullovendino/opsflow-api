<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrgEntityStatus;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Departments\IndexDepartmentsRequest;
use App\Http\Requests\Api\V1\Departments\StoreDepartmentRequest;
use App\Http\Requests\Api\V1\Departments\UpdateDepartmentRequest;
use App\Http\Requests\Api\V1\Departments\UpdateDepartmentStatusRequest;
use App\Http\Resources\Api\V1\DepartmentResource;
use App\Models\Department;
use App\Models\User;
use App\Services\Departments\DepartmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class DepartmentController extends BaseApiController
{
    public function __construct(
        private readonly DepartmentService $departmentService,
    ) {}

    public function index(IndexDepartmentsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        $departments = $this->departmentService->list($request->filters());

        return $this->paginatedResponse(
            paginator: $departments,
            data: DepartmentResource::collection($departments),
            message: 'Departments retrieved successfully.',
        );
    }

    public function show(Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        $department = $this->departmentService->find($department);

        return $this->successResponse(
            data: new DepartmentResource($department),
            message: 'Department retrieved successfully.',
        );
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $this->authorize('create', Department::class);

        /** @var User $actor */
        $actor = $request->user();

        $department = $this->departmentService->create($request->validated(), $actor);

        return $this->successResponse(
            data: new DepartmentResource($department),
            message: 'Department created successfully.',
            status: Response::HTTP_CREATED,
        );
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        /** @var User $actor */
        $actor = $request->user();

        $department = $this->departmentService->update($department, $request->validated(), $actor);

        return $this->successResponse(
            data: new DepartmentResource($department),
            message: 'Department updated successfully.',
        );
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        /** @var User $actor */
        $actor = request()->user();

        $this->departmentService->delete($department, $actor);

        return $this->successResponse(
            message: 'Department deleted successfully.',
        );
    }

    public function updateStatus(UpdateDepartmentStatusRequest $request, Department $department): JsonResponse
    {
        $this->authorize('updateStatus', $department);

        $status = $request->enum('status', OrgEntityStatus::class);

        /** @var User $actor */
        $actor = $request->user();

        $department = $this->departmentService->changeStatus($department, $status, $actor);

        return $this->successResponse(
            data: new DepartmentResource($department),
            message: 'Department status updated successfully.',
        );
    }
}
