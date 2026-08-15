<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Departments;

use App\Enums\OrgEntityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(OrgEntityStatus::class)],
        ];
    }
}
