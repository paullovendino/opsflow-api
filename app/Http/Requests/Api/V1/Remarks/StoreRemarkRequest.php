<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Remarks;

use App\Services\Remarks\RemarkService;
use Illuminate\Foundation\Http\FormRequest;

class StoreRemarkRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:'.RemarkService::MAX_BODY_LENGTH],
            'mentioned_user_ids' => ['sometimes', 'nullable', 'array'],
            'mentioned_user_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array{body: string, mentioned_user_ids: list<int>}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        /** @var list<int> $mentionIds */
        $mentionIds = array_values(array_map(
            static fn ($id): int => (int) $id,
            $validated['mentioned_user_ids'] ?? [],
        ));

        return [
            'body' => (string) $validated['body'],
            'mentioned_user_ids' => $mentionIds,
        ];
    }
}
