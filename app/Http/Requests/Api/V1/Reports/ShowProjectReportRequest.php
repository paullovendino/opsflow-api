<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Reports;

use Illuminate\Foundation\Http\FormRequest;

class ShowProjectReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ];
    }

    /**
     * @return array{from_date?: string|null, to_date?: string|null}
     */
    public function dateRange(): array
    {
        $validated = $this->validated();

        return [
            'from_date' => $validated['from_date'] ?? null,
            'to_date' => $validated['to_date'] ?? null,
        ];
    }
}
