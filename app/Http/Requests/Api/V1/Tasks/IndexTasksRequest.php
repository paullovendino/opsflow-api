<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Queries\Tasks\TaskQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $perPage = $this->input('per_page', TaskQuery::DEFAULT_PER_PAGE);

        if (is_numeric($perPage) && (int) $perPage > TaskQuery::MAX_PER_PAGE) {
            $perPage = TaskQuery::MAX_PER_PAGE;
        }

        $this->merge([
            'page' => $this->input('page', 1),
            'per_page' => $perPage,
            'sort' => $this->input('sort', TaskQuery::DEFAULT_SORT),
            'direction' => strtolower((string) $this->input('direction', TaskQuery::DEFAULT_DIRECTION)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', 'nullable', Rule::enum(TaskPriority::class)],
            'project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'created_by' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'sort' => ['sometimes', 'string', Rule::in(TaskQuery::ALLOWED_SORTS)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.TaskQuery::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{
     *     search?: string|null,
     *     status?: string|null,
     *     priority?: string|null,
     *     project_id?: int|null,
     *     assigned_to?: int|null,
     *     created_by?: int|null,
     *     sort: string,
     *     direction: string,
     *     page: int,
     *     per_page: int
     * }
     */
    public function filters(): array
    {
        /** @var array{
         *     search?: string|null,
         *     status?: TaskStatus|string|null,
         *     priority?: TaskPriority|string|null,
         *     project_id?: int|null,
         *     assigned_to?: int|null,
         *     created_by?: int|null,
         *     sort: string,
         *     direction: string,
         *     page: int,
         *     per_page: int
         * } $validated
         */
        $validated = $this->validated();

        $status = $validated['status'] ?? null;
        if ($status instanceof TaskStatus) {
            $status = $status->value;
        }

        $priority = $validated['priority'] ?? null;
        if ($priority instanceof TaskPriority) {
            $priority = $priority->value;
        }

        return [
            'search' => $validated['search'] ?? null,
            'status' => $status,
            'priority' => $priority,
            'project_id' => $validated['project_id'] ?? null,
            'assigned_to' => $validated['assigned_to'] ?? null,
            'created_by' => $validated['created_by'] ?? null,
            'sort' => $validated['sort'] ?? TaskQuery::DEFAULT_SORT,
            'direction' => $validated['direction'] ?? TaskQuery::DEFAULT_DIRECTION,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? TaskQuery::DEFAULT_PER_PAGE),
        ];
    }
}
