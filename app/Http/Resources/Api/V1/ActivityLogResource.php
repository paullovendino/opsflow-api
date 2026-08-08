<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Remark;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $action = $this->action instanceof ActivityAction
            ? $this->action->value
            : (string) $this->action;

        return [
            'id' => $this->id,
            'action' => $action,
            'description' => $this->description,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject' => $this->subjectSummary(),
            'actor' => $this->actorSummary(),
            'properties' => $this->publicProperties(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return array{id: int, full_name: string, email: string}|null
     */
    private function actorSummary(): ?array
    {
        if ($this->actor !== null) {
            return [
                'id' => $this->actor->id,
                'full_name' => $this->actor->full_name,
                'email' => $this->actor->email,
            ];
        }

        $snapshot = $this->properties['actor_snapshot'] ?? null;

        return is_array($snapshot) ? [
            'id' => (int) ($snapshot['id'] ?? 0),
            'full_name' => (string) ($snapshot['full_name'] ?? ''),
            'email' => (string) ($snapshot['email'] ?? ''),
        ] : null;
    }

    /**
     * @return array{id: int, type: string, name?: string, title?: string, full_name?: string}|null
     */
    private function subjectSummary(): ?array
    {
        $subject = $this->subject;

        if (! $subject instanceof Model) {
            return null;
        }

        $summary = [
            'id' => (int) $subject->getKey(),
            'type' => $this->subject_type,
        ];

        if ($subject instanceof Project) {
            $summary['name'] = $subject->name;
        } elseif ($subject instanceof Task) {
            $summary['title'] = $subject->title;
        } elseif ($subject instanceof User) {
            $summary['full_name'] = $subject->full_name;
        } elseif ($subject instanceof Remark) {
            $summary['body_preview'] = mb_strlen($subject->body) > 80
                ? mb_substr($subject->body, 0, 79).'…'
                : $subject->body;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicProperties(): array
    {
        $properties = is_array($this->properties) ? $this->properties : [];
        unset($properties['actor_snapshot']);

        return $properties;
    }
}
