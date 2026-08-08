<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Projects\IndexProjectsRequest;
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

    public function index(IndexProjectsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        /** @var User $actor */
        $actor = $request->user();

        $projects = $this->projectService->list(
            actor: $actor,
            filters: $request->filters(),
        );

        return $this->paginatedResponse(
            paginator: $projects,
            data: ProjectResource::collection($projects),
            message: 'Projects retrieved successfully.',
        );
    }

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project = $this->projectService->find($project);

        return $this->successResponse(
            data: new ProjectResource($project),
            message: 'Project retrieved successfully.',
        );
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $this->authorize('create', Project::class);

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
        $this->authorize('update', $project);

        /** @var User $actor */
        $actor = $request->user();

        $project = $this->projectService->update($project, $request->validated(), $actor);

        return $this->successResponse(
            data: new ProjectResource($project),
            message: 'Project updated successfully.',
        );
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        /** @var User $actor */
        $actor = request()->user();

        $this->projectService->delete($project, $actor);

        return $this->successResponse(
            message: 'Project deleted successfully.',
        );
    }

    public function updateStatus(UpdateProjectStatusRequest $request, Project $project): JsonResponse
    {
        $this->authorize('updateStatus', $project);

        $status = $request->enum('status', ProjectStatus::class);

        /** @var User $actor */
        $actor = $request->user();

        $project = $this->projectService->changeStatus($project, $status, $actor);

        return $this->successResponse(
            data: new ProjectResource($project),
            message: 'Project status updated successfully.',
        );
    }

    public function members(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $members = $this->projectService->listMembers($project);

        return $this->successResponse(
            data: ProjectMemberResource::collection($members),
            message: 'Project members retrieved successfully.',
        );
    }

    public function storeMember(StoreProjectMemberRequest $request, Project $project): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        /** @var array{user_id: int} $validated */
        $validated = $request->validated();

        /** @var User $actor */
        $actor = $request->user();

        $member = $this->projectService->addMember($project, $validated['user_id'], $actor);

        return $this->successResponse(
            data: new ProjectMemberResource($member),
            message: 'Project member added successfully.',
            status: Response::HTTP_CREATED,
        );
    }

    public function destroyMember(Project $project, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        /** @var User $actor */
        $actor = request()->user();

        $this->projectService->removeMember($project, $user, $actor);

        return $this->successResponse(
            message: 'Project member removed successfully.',
        );
    }
}
