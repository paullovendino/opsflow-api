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
     * Active departments only, ordered by name.
     *
     * @return Collection<int, Department>
     */
    public function departments(): Collection
    {
        return $this->orderedByName(Department::query()->active())->get();
    }

    /**
     * Active job titles only. Optionally filter by department and/or include one inactive id for edit forms.
     *
     * @return Collection<int, JobTitle>
     */
    public function jobTitles(?int $departmentId = null, ?int $includeId = null): Collection
    {
        $query = JobTitle::query();

        $query->where(function (Builder $builder) use ($departmentId, $includeId): void {
            $builder->where(function (Builder $active) use ($departmentId): void {
                $active->active();

                if ($departmentId !== null) {
                    $active->forDepartment($departmentId);
                }
            });

            if ($includeId !== null) {
                $builder->orWhere('id', $includeId);
            }
        });

        return $this->orderedByName($query)->get();
    }

    /**
     * Active job titles for a department (dependent dropdown), with optional include_id.
     *
     * @return Collection<int, JobTitle>
     */
    public function jobTitlesForDepartment(Department $department, ?int $includeId = null): Collection
    {
        return $this->jobTitles($department->id, $includeId);
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
