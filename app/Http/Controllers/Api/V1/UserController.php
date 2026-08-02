<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Api\BaseApiController;
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

    public function index(): JsonResponse
    {
        $users = $this->userService->list();

        return $this->successResponse(
            data: UserResource::collection($users),
            message: 'Users retrieved successfully.',
        );
    }

    public function show(User $user): JsonResponse
    {
        $user = $this->userService->find($user);

        return $this->successResponse(
            data: new UserResource($user),
            message: 'User retrieved successfully.',
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return $this->successResponse(
            data: new UserResource($user),
            message: 'User created successfully.',
            status: Response::HTTP_CREATED,
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->update($user, $request->validated());

        return $this->successResponse(
            data: new UserResource($user),
            message: 'User updated successfully.',
        );
    }

    public function destroy(User $user): JsonResponse
    {
        $this->userService->delete($user);

        return $this->successResponse(
            message: 'User deleted successfully.',
        );
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $status = $request->enum('status', UserStatus::class);

        $user = $this->userService->changeStatus($user, $status);

        return $this->successResponse(
            data: new UserResource($user),
            message: 'User status updated successfully.',
        );
    }
}
