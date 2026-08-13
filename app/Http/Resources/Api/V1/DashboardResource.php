<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{
 *     projects: array{total: int, by_status: array<string, int>, average_progress: int|null},
 *     tasks: array{
 *         total: int,
 *         by_status: array<string, int>,
 *         by_priority: array<string, int>,
 *         overdue: int,
 *         assigned_to_me: int,
 *         due_soon: int
 *     },
 *     recent: list<array<string, mixed>>,
 *     due_soon: list<array<string, mixed>>,
 *     recent_activity: list<array<string, mixed>>,
 *     notifications: array{unread_count: int}
 * } $resource
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array{
     *     projects: array{total: int, by_status: array<string, int>, average_progress: int|null},
     *     tasks: array{
     *         total: int,
     *         by_status: array<string, int>,
     *         by_priority: array<string, int>,
     *         overdue: int,
     *         assigned_to_me: int,
     *         due_soon: int
     *     },
     *     recent: list<array<string, mixed>>,
     *     due_soon: list<array<string, mixed>>,
     *     recent_activity: list<array<string, mixed>>,
     *     notifications: array{unread_count: int}
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{
         *     projects: array{total: int, by_status: array<string, int>, average_progress: int|null},
         *     tasks: array{
         *         total: int,
         *         by_status: array<string, int>,
         *         by_priority: array<string, int>,
         *         overdue: int,
         *         assigned_to_me: int,
         *         due_soon: int
         *     },
         *     recent: list<array<string, mixed>>,
         *     due_soon: list<array<string, mixed>>,
         *     recent_activity: list<array<string, mixed>>,
         *     notifications: array{unread_count: int}
         * } $data
         */
        $data = $this->resource;

        return [
            'projects' => $data['projects'],
            'tasks' => $data['tasks'],
            'recent' => $data['recent'],
            'due_soon' => $data['due_soon'],
            'recent_activity' => $data['recent_activity'],
            'notifications' => $data['notifications'],
        ];
    }
}
