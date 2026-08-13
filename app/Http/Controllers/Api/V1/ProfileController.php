<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Profile\UpdateProfileRequest;
use App\Http\Resources\Api\V1\ProfileResource;
use App\Models\User;
use App\Services\Profile\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends BaseApiController
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $profile = $this->profileService->show($user);

        return $this->successResponse(
            data: new ProfileResource($profile),
            message: 'Profile retrieved successfully.',
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $profile = $this->profileService->update($user, $request->profilePayload());

        return $this->successResponse(
            data: new ProfileResource($profile),
            message: 'Profile updated successfully.',
        );
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $profile = $this->profileService->removeAvatar($user);

        return $this->successResponse(
            data: new ProfileResource($profile),
            message: 'Avatar removed successfully.',
        );
    }
}
