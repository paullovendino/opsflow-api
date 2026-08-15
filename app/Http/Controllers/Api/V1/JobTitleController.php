<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrgEntityStatus;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\JobTitles\IndexJobTitlesRequest;
use App\Http\Requests\Api\V1\JobTitles\StoreJobTitleRequest;
use App\Http\Requests\Api\V1\JobTitles\UpdateJobTitleRequest;
use App\Http\Requests\Api\V1\JobTitles\UpdateJobTitleStatusRequest;
use App\Http\Resources\Api\V1\JobTitleResource;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\JobTitles\JobTitleService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class JobTitleController extends BaseApiController
{
    public function __construct(
        private readonly JobTitleService $jobTitleService,
    ) {}

    public function index(IndexJobTitlesRequest $request): JsonResponse
    {
        $this->authorize('viewAny', JobTitle::class);

        $jobTitles = $this->jobTitleService->list($request->filters());

        return $this->paginatedResponse(
            paginator: $jobTitles,
            data: JobTitleResource::collection($jobTitles),
            message: 'Job titles retrieved successfully.',
        );
    }

    public function indexForDepartment(IndexJobTitlesRequest $request, Department $department): JsonResponse
    {
        $this->authorize('viewAny', JobTitle::class);

        $filters = $request->filters();
        $filters['department_id'] = $department->id;

        $jobTitles = $this->jobTitleService->list($filters);

        return $this->paginatedResponse(
            paginator: $jobTitles,
            data: JobTitleResource::collection($jobTitles),
            message: 'Job titles retrieved successfully.',
        );
    }

    public function show(JobTitle $jobTitle): JsonResponse
    {
        $this->authorize('view', $jobTitle);

        $jobTitle = $this->jobTitleService->find($jobTitle);

        return $this->successResponse(
            data: new JobTitleResource($jobTitle),
            message: 'Job title retrieved successfully.',
        );
    }

    public function store(StoreJobTitleRequest $request): JsonResponse
    {
        $this->authorize('create', JobTitle::class);

        /** @var User $actor */
        $actor = $request->user();

        $jobTitle = $this->jobTitleService->create($request->validated(), $actor);

        return $this->successResponse(
            data: new JobTitleResource($jobTitle),
            message: 'Job title created successfully.',
            status: Response::HTTP_CREATED,
        );
    }

    public function update(UpdateJobTitleRequest $request, JobTitle $jobTitle): JsonResponse
    {
        $this->authorize('update', $jobTitle);

        /** @var User $actor */
        $actor = $request->user();

        $jobTitle = $this->jobTitleService->update($jobTitle, $request->validated(), $actor);

        return $this->successResponse(
            data: new JobTitleResource($jobTitle),
            message: 'Job title updated successfully.',
        );
    }

    public function destroy(JobTitle $jobTitle): JsonResponse
    {
        $this->authorize('delete', $jobTitle);

        /** @var User $actor */
        $actor = request()->user();

        $this->jobTitleService->delete($jobTitle, $actor);

        return $this->successResponse(
            message: 'Job title deleted successfully.',
        );
    }

    public function updateStatus(UpdateJobTitleStatusRequest $request, JobTitle $jobTitle): JsonResponse
    {
        $this->authorize('updateStatus', $jobTitle);

        $status = $request->enum('status', OrgEntityStatus::class);

        /** @var User $actor */
        $actor = $request->user();

        $jobTitle = $this->jobTitleService->changeStatus($jobTitle, $status, $actor);

        return $this->successResponse(
            data: new JobTitleResource($jobTitle),
            message: 'Job title status updated successfully.',
        );
    }
}
