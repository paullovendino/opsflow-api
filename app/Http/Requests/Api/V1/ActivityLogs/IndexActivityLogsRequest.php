<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\ActivityLogs;

use App\Enums\ActivityAction;
use App\Queries\ActivityLogs\ActivityLogQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexActivityLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $perPage = $this->input('per_page', ActivityLogQuery::DEFAULT_PER_PAGE);

        if (is_numeric($perPage) && (int) $perPage > ActivityLogQuery::MAX_PER_PAGE) {
            $perPage = ActivityLogQuery::MAX_PER_PAGE;
        }

        $this->merge([
            'page' => $this->input('page', 1),
            'per_page' => $perPage,
            'direction' => strtolower((string) $this->input('direction', ActivityLogQuery::DEFAULT_DIRECTION)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'actor_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'action' => ['sometimes', 'nullable', 'string', Rule::enum(ActivityAction::class)],
            'subject_type' => ['sometimes', 'nullable', 'string', Rule::in(['user', 'project', 'task'])],
            'subject_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ActivityLogQuery::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{
     *     actor_id?: int|null,
     *     action?: string|null,
     *     subject_type?: string|null,
     *     subject_id?: int|null,
     *     from?: string|null,
     *     to?: string|null,
     *     direction: string,
     *     page: int,
     *     per_page: int
     * }
     */
    public function filters(): array
    {
        /** @var array{
         *     actor_id?: int|null,
         *     action?: string|\App\Enums\ActivityAction|null,
         *     subject_type?: string|null,
         *     subject_id?: int|null,
         *     from?: string|null,
         *     to?: string|null,
         *     direction: string,
         *     page: int,
         *     per_page: int
         * } $validated
         */
        $validated = $this->validated();

        $action = $validated['action'] ?? null;
        if ($action instanceof ActivityAction) {
            $action = $action->value;
        }

        return [
            'actor_id' => isset($validated['actor_id']) ? (int) $validated['actor_id'] : null,
            'action' => $action,
            'subject_type' => $validated['subject_type'] ?? null,
            'subject_id' => isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'direction' => $validated['direction'] ?? ActivityLogQuery::DEFAULT_DIRECTION,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? ActivityLogQuery::DEFAULT_PER_PAGE),
        ];
    }
}
