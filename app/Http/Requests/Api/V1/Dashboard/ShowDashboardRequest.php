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
        $this->merge([
            'recent_limit' => $this->clampLimit(
                $this->input('recent_limit', DashboardService::DEFAULT_RECENT_LIMIT),
                DashboardService::MAX_RECENT_LIMIT,
            ),
            'activity_limit' => $this->clampLimit(
                $this->input('activity_limit', DashboardService::DEFAULT_ACTIVITY_LIMIT),
                DashboardService::MAX_ACTIVITY_LIMIT,
            ),
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
            'activity_limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.DashboardService::MAX_ACTIVITY_LIMIT,
            ],
        ];
    }

    public function recentLimit(): int
    {
        return (int) ($this->validated()['recent_limit'] ?? DashboardService::DEFAULT_RECENT_LIMIT);
    }

    public function activityLimit(): int
    {
        return (int) ($this->validated()['activity_limit'] ?? DashboardService::DEFAULT_ACTIVITY_LIMIT);
    }

    private function clampLimit(mixed $limit, int $max): mixed
    {
        if (! is_numeric($limit)) {
            return $limit;
        }

        $limit = (int) $limit;

        if ($limit > $max) {
            $limit = $max;
        }

        if ($limit < 1) {
            $limit = 1;
        }

        return $limit;
    }
}
