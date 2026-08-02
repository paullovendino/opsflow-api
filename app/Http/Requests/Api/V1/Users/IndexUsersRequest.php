<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Users;

use App\Enums\UserStatus;
use App\Queries\Users\UserQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $perPage = $this->input('per_page', UserQuery::DEFAULT_PER_PAGE);

        if (is_numeric($perPage) && (int) $perPage > UserQuery::MAX_PER_PAGE) {
            $perPage = UserQuery::MAX_PER_PAGE;
        }

        $this->merge([
            'page' => $this->input('page', 1),
            'per_page' => $perPage,
            'sort' => $this->input('sort', UserQuery::DEFAULT_SORT),
            'direction' => strtolower((string) $this->input('direction', UserQuery::DEFAULT_DIRECTION)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role_id' => ['sometimes', 'nullable', 'integer', 'exists:roles,id'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'job_title_id' => ['sometimes', 'nullable', 'integer', 'exists:job_titles,id'],
            'status' => ['sometimes', 'nullable', Rule::enum(UserStatus::class)],
            'sort' => ['sometimes', 'string', Rule::in(UserQuery::ALLOWED_SORTS)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.UserQuery::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{
     *     search?: string|null,
     *     role_id?: int|null,
     *     department_id?: int|null,
     *     job_title_id?: int|null,
     *     status?: string|null,
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
         *     role_id?: int|null,
         *     department_id?: int|null,
         *     job_title_id?: int|null,
         *     status?: string|null,
         *     sort: string,
         *     direction: string,
         *     page: int,
         *     per_page: int
         * } $validated
         */
        $validated = $this->validated();

        $status = $validated['status'] ?? null;
        if ($status instanceof UserStatus) {
            $status = $status->value;
        }

        return [
            'search' => $validated['search'] ?? null,
            'role_id' => $validated['role_id'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'job_title_id' => $validated['job_title_id'] ?? null,
            'status' => $status,
            'sort' => $validated['sort'] ?? UserQuery::DEFAULT_SORT,
            'direction' => $validated['direction'] ?? UserQuery::DEFAULT_DIRECTION,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? UserQuery::DEFAULT_PER_PAGE),
        ];
    }
}
