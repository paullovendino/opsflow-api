<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;

class DashboardPolicy
{
    public function view(User $actor): bool
    {
        return $this->isAdministrator($actor)
            || $this->isProjectManager($actor)
            || $this->isEmployee($actor);
    }

    private function isAdministrator(User $user): bool
    {
        return $this->roleName($user) === RoleName::Administrator;
    }

    private function isProjectManager(User $user): bool
    {
        return $this->roleName($user) === RoleName::ProjectManager;
    }

    private function isEmployee(User $user): bool
    {
        return $this->roleName($user) === RoleName::Employee;
    }

    private function roleName(User $user): ?RoleName
    {
        $user->loadMissing('role');

        $name = $user->role?->name;

        return $name instanceof RoleName ? $name : null;
    }
}
