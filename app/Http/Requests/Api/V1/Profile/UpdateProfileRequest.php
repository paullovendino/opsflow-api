<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Profile;

use App\Enums\ThemePreference;
use App\Services\Profile\ProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('password') && $this->input('password') === '') {
            $this->merge([
                'password' => null,
                'password_confirmation' => null,
            ]);
        }

        foreach ([
            'notify_task_assigned',
            'notify_task_status',
            'notify_remarks',
            'notify_mentions',
        ] as $flag) {
            if ($this->exists($flag)) {
                $this->merge([
                    $flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'confirmed', Password::defaults()],
            'avatar' => [
                'sometimes',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:'.ProfileService::AVATAR_MAX_KB,
            ],
            'theme_preference' => ['sometimes', 'required', Rule::enum(ThemePreference::class)],
            'notify_task_assigned' => ['sometimes', 'required', 'boolean'],
            'notify_task_status' => ['sometimes', 'required', 'boolean'],
            'notify_remarks' => ['sometimes', 'required', 'boolean'],
            'notify_mentions' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * Allowlisted validated payload for the profile service.
     *
     * @return array<string, mixed>
     */
    public function profilePayload(): array
    {
        $payload = $this->safe()->only([
            'first_name',
            'middle_name',
            'last_name',
            'password',
            'theme_preference',
            'notify_task_assigned',
            'notify_task_status',
            'notify_remarks',
            'notify_mentions',
        ]);

        if ($this->hasFile('avatar')) {
            /** @var UploadedFile $avatar */
            $avatar = $this->file('avatar');
            $payload['avatar'] = $avatar;
        }

        return $payload;
    }
}
