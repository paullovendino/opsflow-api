<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'role' => $this->whenLoaded('role', fn (): array => [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'description' => $this->role->description,
            ]),
            'department' => $this->whenLoaded('department', fn (): ?array => $this->department === null
                ? null
                : [
                    'id' => $this->department->id,
                    'name' => $this->department->name,
                    'code' => $this->department->code,
                    'description' => $this->department->description,
                ]),
            'job_title' => $this->whenLoaded('jobTitle', fn (): ?array => $this->jobTitle === null
                ? null
                : [
                    'id' => $this->jobTitle->id,
                    'name' => $this->jobTitle->name,
                    'code' => $this->jobTitle->code,
                    'description' => $this->jobTitle->description,
                ]),
        ];
    }
}
