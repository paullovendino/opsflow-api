<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Users;

use App\Enums\OrgEntityStatus;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\JobTitle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->departmentIdIsEmpty()) {
            $this->merge(['job_title_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'job_title_id' => ['nullable', 'integer', 'exists:job_titles,id'],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'avatar' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateOrgAssignment($validator);
        });
    }

    private function validateOrgAssignment(Validator $validator): void
    {
        $departmentId = $this->input('department_id');
        $jobTitleId = $this->input('job_title_id');

        if (filled($jobTitleId) && ! filled($departmentId)) {
            $validator->errors()->add(
                'job_title_id',
                'A department is required when assigning a job title.',
            );

            return;
        }

        if (filled($departmentId)) {
            $department = Department::query()->find((int) $departmentId);

            if ($department !== null && $department->status !== OrgEntityStatus::Active) {
                $validator->errors()->add(
                    'department_id',
                    'The selected department is inactive and cannot be newly assigned.',
                );
            }
        }

        if (! filled($jobTitleId) || ! filled($departmentId)) {
            return;
        }

        $jobTitle = JobTitle::query()->find((int) $jobTitleId);

        if ($jobTitle === null) {
            return;
        }

        if ((int) $jobTitle->department_id !== (int) $departmentId) {
            $validator->errors()->add(
                'job_title_id',
                'The selected job title does not belong to the selected department.',
            );
        }

        if ($jobTitle->status !== OrgEntityStatus::Active) {
            $validator->errors()->add(
                'job_title_id',
                'The selected job title is inactive and cannot be newly assigned.',
            );
        }
    }

    private function departmentIdIsEmpty(): bool
    {
        $departmentId = $this->input('department_id');

        return $departmentId === null || $departmentId === '' || $departmentId === false;
    }
}
