<?php

declare(strict_types=1);

namespace App\Services\Lookups;

use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class LookupService
{
    /**
     * @return Collection<int, Role>
     */
    public function roles(): Collection
    {
        return $this->orderedByName(Role::query())->get();
    }

    /**
     * @return Collection<int, Department>
     */
    public function departments(): Collection
    {
        return $this->orderedByName(Department::query())->get();
    }

    /**
     * @return Collection<int, JobTitle>
     */
    public function jobTitles(): Collection
    {
        return $this->orderedByName(JobTitle::query())->get();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function orderedByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }
}
