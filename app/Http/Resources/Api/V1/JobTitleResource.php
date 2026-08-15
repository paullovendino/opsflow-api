<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\JobTitle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobTitle
 */
class JobTitleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'department_id' => $this->department_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'status' => $this->status,
            'department' => $this->whenLoaded(
                'department',
                fn (): DepartmentResource => new DepartmentResource($this->department),
            ),
            'users_count' => $this->when(isset($this->users_count), $this->users_count),
        ];
    }
}
