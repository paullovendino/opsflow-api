<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\JobTitle;
use App\Models\User;

class JobTitlePolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    public function view(User $actor, JobTitle $jobTitle): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isAdministrator($actor);
    }

    public function update(User $actor, JobTitle $jobTitle): bool
    {
        return $this->isAdministrator($actor);
    }

    public function delete(User $actor, JobTitle $jobTitle): bool
    {
        return $this->isAdministrator($actor);
    }

    public function updateStatus(User $actor, JobTitle $jobTitle): bool
    {
        return $this->isAdministrator($actor);
    }

    private function isAdministrator(User $user): bool
    {
        return $this->roleName($user) === RoleName::Administrator;
    }

    private function isProjectManager(User $user): bool
    {
        return $this->roleName($user) === RoleName::ProjectManager;
    }

    private function roleName(User $user): ?RoleName
    {
        $user->loadMissing('role');

        $name = $user->role?->name;

        return $name instanceof RoleName ? $name : null;
    }
}
