<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;

class UserService
{
    /**
     * @return Collection<int, User>
     */
    public function list(): Collection
    {
        return User::query()
            ->with(['role', 'department', 'jobTitle'])
            ->orderBy('id')
            ->get();
    }

    public function find(User $user): User
    {
        return $user->loadMissing(['role', 'department', 'jobTitle']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        $user = User::query()->create([
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role_id' => $data['role_id'],
            'department_id' => $data['department_id'] ?? null,
            'job_title_id' => $data['job_title_id'] ?? null,
            'status' => $data['status'],
            'avatar' => $data['avatar'] ?? null,
        ]);

        return $user->load(['role', 'department', 'jobTitle']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        $attributes = [
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'role_id' => $data['role_id'],
            'department_id' => $data['department_id'] ?? null,
            'job_title_id' => $data['job_title_id'] ?? null,
            'status' => $data['status'],
            'avatar' => $data['avatar'] ?? null,
        ];

        if (array_key_exists('password', $data) && filled($data['password'])) {
            $attributes['password'] = Hash::make($data['password']);
        }

        $user->update($attributes);

        return $user->fresh(['role', 'department', 'jobTitle']) ?? $user->load(['role', 'department', 'jobTitle']);
    }

    public function delete(User $user): void
    {
        $user->delete();
    }

    public function changeStatus(User $user, UserStatus $status): User
    {
        $user->update([
            'status' => $status,
        ]);

        return $user->fresh(['role', 'department', 'jobTitle']) ?? $user->load(['role', 'department', 'jobTitle']);
    }
}
