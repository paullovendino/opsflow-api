<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notifications;

use App\Queries\Notifications\NotificationQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $perPage = $this->input('per_page', NotificationQuery::DEFAULT_PER_PAGE);

        if (is_numeric($perPage) && (int) $perPage > NotificationQuery::MAX_PER_PAGE) {
            $perPage = NotificationQuery::MAX_PER_PAGE;
        }

        $unread = $this->input('unread');

        $this->merge([
            'page' => $this->input('page', 1),
            'per_page' => $perPage,
            'unread' => $unread === '1' || $unread === 1 || $unread === true || $unread === 'true',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.NotificationQuery::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{unread: bool, page: int, per_page: int}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'unread' => (bool) ($validated['unread'] ?? false),
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? NotificationQuery::DEFAULT_PER_PAGE),
        ];
    }
}
