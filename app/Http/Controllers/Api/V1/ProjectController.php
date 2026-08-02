<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Projects\StoreProjectMemberRequest;
use App\Http\Requests\Api\V1\Projects\StoreProjectRequest;
use App\Http\Requests\Api\V1\Projects\UpdateProjectRequest;
use App\Http\Requests\Api\V1\Projects\UpdateProjectStatusRequest;
use App\Http\Resources\Api\V1\ProjectMemberResource;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ProjectController extends BaseApiController
{
    public function __construct(
        private readonly ProjectService $projectService,
    ) {}

    public function index(): JsonResponse
    {
        $projects = $this->projectService->list();

        return $this->successResponse(
            data: ProjectResource::collection($projects),
            message: 'Projects retrieved successfully.',
        );
    }

    public function show(Project $project): JsonResponse
    {
        $project = $this->projectService->find($project);

        return $this->successResponse(
            data: new ProjectResource($project),
            message: 'Project retrieved successfully.',
        );
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = $this->projectService->create($request->validated(), $user);

        return $this->successResponse(
            data: new ProjectResource($project),
            message: 'Project created successfully.',
            status: Response::HTTP_CREATED,
        );
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project = $this->projectService->update($project, $request->validated());

        return $this->successResponse(
            data: new ProjectResource($project),
            message: 'Project updated successfully.',
        );
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->projectService->delete($project);

        return $this->successResponse(
            message: 'Project deleted successfully.',
        );
    }

    public function updateStatus(UpdateProjectStatusRequest $request, Project $project): JsonResponse
    {
        $status = $request->enum('status', ProjectStatus::class);

        $project = $this->projectService->changeStatus($project, $status);

        return $this->successResponse(
            data: new ProjectResource($project),
            message: 'Project status updated successfully.',
        );
    }

    public function members(Project $project): JsonResponse
    {
        $members = $this->projectService->listMembers($project);

        return $this->successResponse(
            data: ProjectMemberResource::collection($members),
            message: 'Project members retrieved successfully.',
        );
    }

    public function storeMember(StoreProjectMemberRequest $request, Project $project): JsonResponse
    {
        /** @var array{user_id: int} $validated */
        $validated = $request->validated();

        $member = $this->projectService->addMember($project, $validated['user_id']);

        return $this->successResponse(
            data: new ProjectMemberResource($member),
            message: 'Project member added successfully.',
            status: Response::HTTP_CREATED,
        );
    }

    public function destroyMember(Project $project, User $user): JsonResponse
    {
        $this->projectService->removeMember($project, $user);

        return $this->successResponse(
            message: 'Project member removed successfully.',
        );
    }
}
