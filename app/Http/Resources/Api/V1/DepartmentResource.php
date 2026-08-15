<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'status' => $this->status,
            'job_titles_count' => $this->when(isset($this->job_titles_count), $this->job_titles_count),
            'users_count' => $this->when(isset($this->users_count), $this->users_count),
        ];
    }
}
