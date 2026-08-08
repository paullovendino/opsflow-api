<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Users\IndexUsersRequest;
use App\Http\Requests\Api\V1\Users\StoreUserRequest;
use App\Http\Requests\Api\V1\Users\UpdateUserRequest;
use App\Http\Requests\Api\V1\Users\UpdateUserStatusRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UserController extends BaseApiController
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    public function index(IndexUsersRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = $this->userService->list($request->filters());

        return $this->paginatedResponse(
            paginator: $users,
            data: UserResource::collection($users),
            message: 'Users retrieved successfully.',
        );
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user = $this->userService->find($user);

        return $this->successResponse(
            data: new UserResource($user),
            message: 'User retrieved successfully.',
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        /** @var User $actor */
        $actor = $request->user();

        $user = $this->userService->create($request->validated(), $actor);

        return $this->successResponse(
            data: new UserResource($user),
            message: 'User created successfully.',
            status: Response::HTTP_CREATED,
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        /** @var User $actor */
        $actor = $request->user();

        $user = $this->userService->update($user, $request->validated(), $actor);

        return $this->successResponse(
            data: new UserResource($user),
            message: 'User updated successfully.',
        );
    }

    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $this->userService->delete($user);

        return $this->successResponse(
            message: 'User deleted successfully.',
        );
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $this->authorize('updateStatus', $user);

        $status = $request->enum('status', UserStatus::class);

        /** @var User $actor */
        $actor = $request->user();

        $user = $this->userService->changeStatus($user, $status, $actor);

        return $this->successResponse(
            data: new UserResource($user),
            message: 'User status updated successfully.',
        );
    }
}
