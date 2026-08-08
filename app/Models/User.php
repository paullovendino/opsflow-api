<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'role_id',
    'department_id',
    'job_title_id',
    'first_name',
    'middle_name',
    'last_name',
    'email',
    'password',
    'avatar',
    'status',
    'last_login_at',
    'theme_preference',
    'notify_task_assigned',
    'notify_task_status',
    'notify_remarks',
    'notify_mentions',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
            'notify_task_assigned' => 'boolean',
            'notify_task_status' => 'boolean',
            'notify_remarks' => 'boolean',
            'notify_mentions' => 'boolean',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->last_name === null || $this->last_name === '') {
                return (string) $this->first_name;
            }

            if ($this->middle_name !== null && $this->middle_name !== '') {
                return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
            }

            return trim("{$this->first_name} {$this->last_name}");
        });
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'created_by');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withPivot(['joined_at'])
            ->withTimestamps();
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function receivedNotifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'recipient_id');
    }
}
