<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class SearchTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'priority' => $this->priority,
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'project' => $this->whenLoaded(
                'project',
                fn (): ?array => $this->project === null
                    ? null
                    : [
                        'id' => $this->project->id,
                        'name' => $this->project->name,
                    ],
            ),
            'type' => 'task',
        ];
    }
}
