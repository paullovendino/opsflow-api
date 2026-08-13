<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{
 *     user: User,
 *     projects: array{owned_count: int, member_count: int},
 *     tasks: array{assigned_open: int, assigned_overdue: int},
 *     recent_activity: list<array<string, mixed>>
 * } $resource
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array{
     *     user: array<string, mixed>,
     *     projects: array{owned_count: int, member_count: int},
     *     tasks: array{assigned_open: int, assigned_overdue: int},
     *     recent_activity: list<array<string, mixed>>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{
         *     user: User,
         *     projects: array{owned_count: int, member_count: int},
         *     tasks: array{assigned_open: int, assigned_overdue: int},
         *     recent_activity: list<array<string, mixed>>
         * } $data
         */
        $data = $this->resource;

        return [
            'user' => (new UserResource($data['user']))->resolve(),
            'projects' => $data['projects'],
            'tasks' => $data['tasks'],
            'recent_activity' => $data['recent_activity'],
        ];
    }
}
