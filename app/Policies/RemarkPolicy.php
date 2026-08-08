<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Remark;
use App\Models\User;

class RemarkPolicy
{
    public function update(User $actor, Remark $remark): bool
    {
        if ($this->isAdministrator($actor)) {
            return true;
        }

        return (int) $remark->author_id === (int) $actor->id;
    }

    public function delete(User $actor, Remark $remark): bool
    {
        return $this->update($actor, $remark);
    }

    private function isAdministrator(User $user): bool
    {
        return $this->roleName($user) === RoleName::Administrator;
    }

    private function roleName(User $user): ?RoleName
    {
        $user->loadMissing('role');

        $name = $user->role?->name;

        return $name instanceof RoleName ? $name : null;
    }
}
