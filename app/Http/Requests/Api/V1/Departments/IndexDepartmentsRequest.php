<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Departments;

use App\Enums\OrgEntityStatus;
use App\Queries\Departments\DepartmentQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexDepartmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $perPage = $this->input('per_page', DepartmentQuery::DEFAULT_PER_PAGE);

        if (is_numeric($perPage) && (int) $perPage > DepartmentQuery::MAX_PER_PAGE) {
            $perPage = DepartmentQuery::MAX_PER_PAGE;
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.DepartmentQuery::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{
     *     q?: string|null,
     *     status?: string|null,
     *     page: int,
     *     per_page: int
     * }
     */
    public function filters(): array
    {
        /** @var array{
         *     q?: string|null,
         *     status?: OrgEntityStatus|string|null,
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
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? DepartmentQuery::DEFAULT_PER_PAGE),
        ];
    }
}
