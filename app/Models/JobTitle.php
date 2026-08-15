<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrgEntityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobTitle extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'name',
        'code',
        'description',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrgEntityStatus::class,
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === OrgEntityStatus::Active;
    }

    /**
     * @param  Builder<JobTitle>  $query
     * @return Builder<JobTitle>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', OrgEntityStatus::Active);
    }

    /**
     * @param  Builder<JobTitle>  $query
     * @return Builder<JobTitle>
     */
    public function scopeForDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }
}
