<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\Profile\ProfileService;
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
            'avatar' => ProfileService::publicAvatarUrl(
                $this->relationLoaded('avatarFile')
                    ? $this->avatarFile
                    : $this->avatarFile()->first(),
                $this->updated_at,
            ),
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'theme_preference' => $this->theme_preference ?? 'system',
            'notify_task_assigned' => (bool) $this->notify_task_assigned,
            'notify_task_status' => (bool) $this->notify_task_status,
            'notify_remarks' => (bool) $this->notify_remarks,
            'notify_mentions' => (bool) $this->notify_mentions,
            'role' => $this->whenLoaded('role', fn (): RoleResource => new RoleResource($this->role)),
            'department' => $this->whenLoaded(
                'department',
                fn (): ?DepartmentResource => $this->department === null
                    ? null
                    : new DepartmentResource($this->department),
            ),
            'job_title' => $this->whenLoaded(
                'jobTitle',
                fn (): ?JobTitleResource => $this->jobTitle === null
                    ? null
                    : new JobTitleResource($this->jobTitle),
            ),
        ];
    }
}
