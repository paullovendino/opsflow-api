<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Search;

use App\Services\Search\SearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $q = $this->input('q');
        if (is_string($q)) {
            $q = trim($q);
        }

        $perType = $this->input('per_type', SearchService::DEFAULT_PER_TYPE);
        if (is_numeric($perType)) {
            $perType = (int) $perType;

            if ($perType > SearchService::MAX_PER_TYPE) {
                $perType = SearchService::MAX_PER_TYPE;
            }

            if ($perType < 1) {
                $perType = 1;
            }
        }

        $merge = [
            'q' => $q,
            'per_type' => $perType,
        ];

        $types = $this->input('types');
        if (is_string($types)) {
            $merge['types'] = array_values(array_filter(array_map(
                static fn (string $type): string => strtolower(trim($type)),
                explode(',', $types),
            ), static fn (string $type): bool => $type !== ''));
        } elseif (is_array($types)) {
            $merge['types'] = array_values(array_filter(array_map(
                static function (mixed $type): string {
                    return is_string($type) ? strtolower(trim($type)) : '';
                },
                $types,
            ), static fn (string $type): bool => $type !== ''));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => [
                'required',
                'string',
                'min:'.SearchService::MIN_QUERY_LENGTH,
                'max:'.SearchService::MAX_QUERY_LENGTH,
            ],
            'per_type' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.SearchService::MAX_PER_TYPE,
            ],
            'types' => [
                'sometimes',
                'array',
            ],
            'types.*' => [
                'string',
                Rule::in(SearchService::ALLOWED_TYPES),
            ],
        ];
    }

    public function queryString(): string
    {
        return (string) $this->validated('q');
    }

    public function perType(): int
    {
        return (int) ($this->validated('per_type') ?? SearchService::DEFAULT_PER_TYPE);
    }

    /**
     * @return list<string>|null
     */
    public function types(): ?array
    {
        /** @var list<string>|null $types */
        $types = $this->validated('types') ?? null;

        return $types;
    }
}
