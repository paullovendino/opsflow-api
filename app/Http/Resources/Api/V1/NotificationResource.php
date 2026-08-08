<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Remark;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof NotificationType
            ? $this->type->value
            : (string) $this->type;

        return [
            'id' => $this->id,
            'type' => $type,
            'actor' => $this->actorSummary(),
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject' => $this->subjectSummary(),
            'data' => is_array($this->data) ? $this->data : [],
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return array{id: int, full_name: string, email: string}|null
     */
    private function actorSummary(): ?array
    {
        if ($this->actor === null) {
            return null;
        }

        return [
            'id' => $this->actor->id,
            'full_name' => $this->actor->full_name,
            'email' => $this->actor->email,
        ];
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
            'type' => (string) $this->subject_type,
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
}
