<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Tasks;

use App\Enums\TaskPriority;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTaskRequest extends FormRequest
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
            'project_id' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->whereNull('deleted_at'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'nullable', Rule::enum(TaskPriority::class)],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'assigned_to' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->whereNull('deleted_at')
                    ->where('status', UserStatus::Active->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_to.exists' => 'The selected assignee must be an active, non-deleted account.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $assignedTo = $this->input('assigned_to');

            if ($assignedTo === null || $assignedTo === '') {
                return;
            }

            /** @var Project|null $project */
            $project = Project::query()->find($this->input('project_id'));

            if ($project === null) {
                return;
            }

            /** @var User|null $assignee */
            $assignee = User::query()->find($assignedTo);

            if ($assignee === null) {
                return;
            }

            $isOwner = (int) $project->created_by === (int) $assignee->id;
            $isMember = $project->members()->where('users.id', $assignee->id)->exists();

            if (! $isOwner && ! $isMember) {
                $validator->errors()->add(
                    'assigned_to',
                    'The selected assignee must be the project owner or a project member.',
                );
            }
        });
    }
}
