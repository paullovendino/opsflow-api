<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{
 *     projects: array{total: int, by_status: array<string, int>},
 *     tasks: array{
 *         total: int,
 *         by_status: array<string, int>,
 *         by_priority: array<string, int>,
 *         overdue: int,
 *         assigned_to_me: int
 *     },
 *     recent: list<array<string, mixed>>
 * } $resource
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array{
     *     projects: array{total: int, by_status: array<string, int>},
     *     tasks: array{
     *         total: int,
     *         by_status: array<string, int>,
     *         by_priority: array<string, int>,
     *         overdue: int,
     *         assigned_to_me: int
     *     },
     *     recent: list<array<string, mixed>>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{
         *     projects: array{total: int, by_status: array<string, int>},
         *     tasks: array{
         *         total: int,
         *         by_status: array<string, int>,
         *         by_priority: array<string, int>,
         *         overdue: int,
         *         assigned_to_me: int
         *     },
         *     recent: list<array<string, mixed>>
         * } $data
         */
        $data = $this->resource;

        return [
            'projects' => $data['projects'],
            'tasks' => $data['tasks'],
            'recent' => $data['recent'],
        ];
    }
}
