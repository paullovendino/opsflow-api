<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Departments;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDepartmentRequest extends FormRequest
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $name = $this->input('name');

            if (! is_string($name) || $name === '') {
                return;
            }

            /** @var Department|null $department */
            $department = $this->route('department');
            $ignoreId = $department instanceof Department ? $department->id : null;

            $query = Department::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }

            if ($query->exists()) {
                $validator->errors()->add('name', 'The name has already been taken.');
            }
        });
    }
}
