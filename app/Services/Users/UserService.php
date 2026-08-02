<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\UserStatus;
use App\Models\User;
use App\Queries\Users\UserQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(
        private readonly UserQuery $userQuery,
    ) {}

    /**
     * @param  array{
     *     search?: string|null,
     *     role_id?: int|null,
     *     department_id?: int|null,
     *     job_title_id?: int|null,
     *     status?: string|null,
     *     sort?: string,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return $this->userQuery->paginate($filters);
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
