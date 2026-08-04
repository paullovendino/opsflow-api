<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Reports;

use App\Enums\ProjectStatus;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProjectReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $perPage = $this->input('per_page', ReportService::DEFAULT_PER_PAGE);

        if (is_numeric($perPage) && (int) $perPage > ReportService::MAX_PER_PAGE) {
            $perPage = ReportService::MAX_PER_PAGE;
        }

        $this->merge([
            'page' => $this->input('page', 1),
            'per_page' => $perPage,
            'sort' => $this->input('sort', ReportService::DEFAULT_PROJECT_SORT),
            'direction' => strtolower((string) $this->input('direction', ReportService::DEFAULT_DIRECTION)),
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
            'from_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'sort' => ['sometimes', 'string', Rule::in(ReportService::ALLOWED_PROJECT_SORTS)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ReportService::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{
     *     search?: string|null,
     *     status?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
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
         *     from_date?: string|null,
         *     to_date?: string|null,
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
            'from_date' => $validated['from_date'] ?? null,
            'to_date' => $validated['to_date'] ?? null,
            'sort' => $validated['sort'] ?? ReportService::DEFAULT_PROJECT_SORT,
            'direction' => $validated['direction'] ?? ReportService::DEFAULT_DIRECTION,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? ReportService::DEFAULT_PER_PAGE),
        ];
    }
}
