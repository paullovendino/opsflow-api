<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Dashboard;

use App\Services\Dashboard\DashboardService;
use Illuminate\Foundation\Http\FormRequest;

class ShowDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $limit = $this->input('recent_limit', DashboardService::DEFAULT_RECENT_LIMIT);

        if (is_numeric($limit)) {
            $limit = (int) $limit;

            if ($limit > DashboardService::MAX_RECENT_LIMIT) {
                $limit = DashboardService::MAX_RECENT_LIMIT;
            }

            if ($limit < 1) {
                $limit = 1;
            }
        }

        $this->merge([
            'recent_limit' => $limit,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recent_limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.DashboardService::MAX_RECENT_LIMIT,
            ],
        ];
    }

    public function recentLimit(): int
    {
        return (int) ($this->validated()['recent_limit'] ?? DashboardService::DEFAULT_RECENT_LIMIT);
    }
}
