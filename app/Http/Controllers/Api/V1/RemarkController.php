<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Remarks\IndexRemarksRequest;
use App\Http\Requests\Api\V1\Remarks\StoreRemarkRequest;
use App\Http\Requests\Api\V1\Remarks\UpdateRemarkRequest;
use App\Http\Resources\Api\V1\RemarkResource;
use App\Models\Project;
use App\Models\Remark;
use App\Models\Task;
use App\Models\User;
use App\Services\Remarks\RemarkService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RemarkController extends BaseApiController
{
    public function __construct(
        private readonly RemarkService $remarkService,
    ) {}

    public function forProject(IndexRemarksRequest $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $remarks = $this->remarkService->list($project, $request->filters());

        return $this->paginatedResponse(
            paginator: $remarks,
            data: RemarkResource::collection($remarks),
            message: 'Remarks retrieved successfully.',
        );
    }

    public function storeForProject(StoreRemarkRequest $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        /** @var User $author */
        $author = $request->user();

        $remark = $this->remarkService->create($project, $request->payload(), $author);

        return $this->successResponse(
            data: new RemarkResource($remark),
            message: 'Remark created successfully.',
            status: Response::HTTP_CREATED,
        );
    }

    public function forTask(IndexRemarksRequest $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $remarks = $this->remarkService->list($task, $request->filters());

        return $this->paginatedResponse(
            paginator: $remarks,
            data: RemarkResource::collection($remarks),
            message: 'Remarks retrieved successfully.',
        );
    }

    public function storeForTask(StoreRemarkRequest $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        /** @var User $author */
        $author = $request->user();

        $remark = $this->remarkService->create($task, $request->payload(), $author);

        return $this->successResponse(
            data: new RemarkResource($remark),
            message: 'Remark created successfully.',
            status: Response::HTTP_CREATED,
        );
    }

    public function update(UpdateRemarkRequest $request, Remark $remark): JsonResponse
    {
        $this->authorize('update', $remark);

        /** @var User $actor */
        $actor = $request->user();

        $remark = $this->remarkService->update($remark, $request->payload(), $actor);

        return $this->successResponse(
            data: new RemarkResource($remark),
            message: 'Remark updated successfully.',
        );
    }

    public function destroy(Remark $remark): JsonResponse
    {
        $this->authorize('delete', $remark);

        /** @var User $actor */
        $actor = request()->user();

        $this->remarkService->delete($remark, $actor);

        return $this->successResponse(
            data: null,
            message: 'Remark deleted successfully.',
        );
    }
}
