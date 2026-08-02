<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends BaseApiController
{
    public function __construct(
        private readonly AuthenticationService $authenticationService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authenticationService->login(
            $request->safe()->only(['email', 'password']),
            $request,
        );

        return $this->successResponse(
            data: [
                'user' => (new UserResource($user))->resolve(),
            ],
            message: 'Login successful.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authenticationService->logout($request);

        return $this->successResponse(
            message: 'Logout successful.',
        );
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->authenticationService->currentUser($request);

        return $this->successResponse(
            data: new UserResource($user),
            message: 'Authenticated user retrieved successfully.',
        );
    }
}
