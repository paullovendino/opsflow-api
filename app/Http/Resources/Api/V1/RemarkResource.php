<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Remark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin Remark
 */
class RemarkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => $this->authorSummary(),
            'mentions' => $this->mentionSummaries(),
            'can_edit' => Gate::allows('update', $this->resource),
            'can_delete' => Gate::allows('delete', $this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }

    /**
     * @return array{id: int, full_name: string, email: string}|null
     */
    private function authorSummary(): ?array
    {
        if ($this->author === null) {
            return null;
        }

        return [
            'id' => $this->author->id,
            'full_name' => $this->author->full_name,
            'email' => $this->author->email,
        ];
    }

    /**
     * @return list<array{id: int, full_name: string, email: string}>
     */
    private function mentionSummaries(): array
    {
        if (! $this->relationLoaded('mentions')) {
            return [];
        }

        $summaries = [];

        foreach ($this->mentions as $mention) {
            if ($mention->relationLoaded('user') && $mention->user !== null) {
                $summaries[] = [
                    'id' => $mention->user->id,
                    'full_name' => $mention->user->full_name,
                    'email' => $mention->user->email,
                ];
            }
        }

        return $summaries;
    }
}
