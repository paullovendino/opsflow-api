<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\JobTitles;

use App\Enums\OrgEntityStatus;
use App\Queries\JobTitles\JobTitleQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexJobTitlesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $perPage = $this->input('per_page', JobTitleQuery::DEFAULT_PER_PAGE);

        if (is_numeric($perPage) && (int) $perPage > JobTitleQuery::MAX_PER_PAGE) {
            $perPage = JobTitleQuery::MAX_PER_PAGE;
        }

        $this->merge([
            'page' => $this->input('page', 1),
            'per_page' => $perPage,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(OrgEntityStatus::class)],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.JobTitleQuery::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{
     *     q?: string|null,
     *     status?: string|null,
     *     department_id?: int|null,
     *     page: int,
     *     per_page: int
     * }
     */
    public function filters(): array
    {
        /** @var array{
         *     q?: string|null,
         *     status?: OrgEntityStatus|string|null,
         *     department_id?: int|null,
         *     page: int,
         *     per_page: int
         * } $validated
         */
        $validated = $this->validated();

        $status = $validated['status'] ?? null;
        if ($status instanceof OrgEntityStatus) {
            $status = $status->value;
        }

        return [
            'q' => $validated['q'] ?? null,
            'status' => $status,
            'department_id' => $validated['department_id'] ?? null,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? JobTitleQuery::DEFAULT_PER_PAGE),
        ];
    }
}
