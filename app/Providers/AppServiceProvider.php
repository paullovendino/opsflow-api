<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Remark;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\DashboardPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RemarkPolicy;
use App\Policies\ReportPolicy;
use App\Policies\TaskPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'department' => Department::class,
            'job_title' => JobTitle::class,
            'project' => Project::class,
            'task' => Task::class,
            'activity_log' => ActivityLog::class,
            'remark' => Remark::class,
            'notification' => Notification::class,
        ]);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);
        Gate::policy(Remark::class, RemarkPolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);

        Route::bind('notification', function (string $value) {
            $user = request()->user();

            if ($user === null) {
                abort(401);
            }

            return Notification::query()
                ->whereKey($value)
                ->where('recipient_id', $user->id)
                ->firstOrFail();
        });
        Gate::define('viewDashboard', [DashboardPolicy::class, 'view']);
        Gate::define('viewAnyProjectReports', [ReportPolicy::class, 'viewAnyProjectReports']);
        Gate::define('viewProjectReport', [ReportPolicy::class, 'viewProjectReport']);
        Gate::define('viewAnyEmployeeReports', [ReportPolicy::class, 'viewAnyEmployeeReports']);
        Gate::define('viewEmployeeReport', [ReportPolicy::class, 'viewEmployeeReport']);

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
