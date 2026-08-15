<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\JobTitles;

use App\Models\JobTitle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateJobTitleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $name = $this->input('name');
            $departmentId = $this->input('department_id');

            if (! is_string($name) || $name === '' || ! is_numeric($departmentId)) {
                return;
            }

            /** @var JobTitle|null $jobTitle */
            $jobTitle = $this->route('jobTitle');
            $ignoreId = $jobTitle instanceof JobTitle ? $jobTitle->id : null;

            $query = JobTitle::query()
                ->where('department_id', (int) $departmentId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }

            if ($query->exists()) {
                $validator->errors()->add('name', 'The name has already been taken for this department.');
            }
        });
    }
}
