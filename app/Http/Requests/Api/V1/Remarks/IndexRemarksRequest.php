<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Remarks;

use App\Queries\Remarks\RemarkQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRemarksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $perPage = $this->input('per_page', RemarkQuery::DEFAULT_PER_PAGE);

        if (is_numeric($perPage) && (int) $perPage > RemarkQuery::MAX_PER_PAGE) {
            $perPage = RemarkQuery::MAX_PER_PAGE;
        }

        $this->merge([
            'page' => $this->input('page', 1),
            'per_page' => $perPage,
            'direction' => strtolower((string) $this->input('direction', RemarkQuery::DEFAULT_DIRECTION)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.RemarkQuery::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{direction: string, page: int, per_page: int}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'direction' => $validated['direction'] ?? RemarkQuery::DEFAULT_DIRECTION,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? RemarkQuery::DEFAULT_PER_PAGE),
        ];
    }
}
