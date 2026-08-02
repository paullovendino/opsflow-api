<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Projects;

use App\Enums\ProjectStatus;
use App\Queries\Projects\ProjectQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $perPage = $this->input('per_page', ProjectQuery::DEFAULT_PER_PAGE);

        if (is_numeric($perPage) && (int) $perPage > ProjectQuery::MAX_PER_PAGE) {
            $perPage = ProjectQuery::MAX_PER_PAGE;
        }

        $this->merge([
            'page' => $this->input('page', 1),
            'per_page' => $perPage,
            'sort' => $this->input('sort', ProjectQuery::DEFAULT_SORT),
            'direction' => strtolower((string) $this->input('direction', ProjectQuery::DEFAULT_DIRECTION)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(ProjectStatus::class)],
            'created_by' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'sort' => ['sometimes', 'string', Rule::in(ProjectQuery::ALLOWED_SORTS)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ProjectQuery::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{
     *     search?: string|null,
     *     status?: string|null,
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
         *     status?: ProjectStatus|string|null,
         *     created_by?: int|null,
         *     sort: string,
         *     direction: string,
         *     page: int,
         *     per_page: int
         * } $validated
         */
        $validated = $this->validated();

        $status = $validated['status'] ?? null;
        if ($status instanceof ProjectStatus) {
            $status = $status->value;
        }

        return [
            'search' => $validated['search'] ?? null,
            'status' => $status,
            'created_by' => $validated['created_by'] ?? null,
            'sort' => $validated['sort'] ?? ProjectQuery::DEFAULT_SORT,
            'direction' => $validated['direction'] ?? ProjectQuery::DEFAULT_DIRECTION,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? ProjectQuery::DEFAULT_PER_PAGE),
        ];
    }
}
