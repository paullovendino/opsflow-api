<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\RemarkController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware(['guest', 'throttle:login'])
            ->name('auth.login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');

        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        Route::patch('/projects/{project}/status', [ProjectController::class, 'updateStatus'])->name('projects.status');
        Route::get('/projects/{project}/members', [ProjectController::class, 'members'])->name('projects.members.index');
        Route::post('/projects/{project}/members', [ProjectController::class, 'storeMember'])->name('projects.members.store');
        Route::delete('/projects/{project}/members/{user}', [ProjectController::class, 'destroyMember'])->name('projects.members.destroy');

        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
        Route::patch('/tasks/{task}/assignment', [TaskController::class, 'updateAssignment'])->name('tasks.assignment');
        Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status');

        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        Route::get('/projects/{project}/activity-logs', [ActivityLogController::class, 'forProject'])->name('projects.activity-logs.index');
        Route::get('/tasks/{task}/activity-logs', [ActivityLogController::class, 'forTask'])->name('tasks.activity-logs.index');
        Route::get('/users/{user}/activity-logs', [ActivityLogController::class, 'forUser'])->name('users.activity-logs.index');

        Route::get('/projects/{project}/remarks', [RemarkController::class, 'forProject'])->name('projects.remarks.index');
        Route::post('/projects/{project}/remarks', [RemarkController::class, 'storeForProject'])->name('projects.remarks.store');
        Route::get('/tasks/{task}/remarks', [RemarkController::class, 'forTask'])->name('tasks.remarks.index');
        Route::post('/tasks/{task}/remarks', [RemarkController::class, 'storeForTask'])->name('tasks.remarks.store');
        Route::put('/remarks/{remark}', [RemarkController::class, 'update'])->name('remarks.update');
        Route::delete('/remarks/{remark}', [RemarkController::class, 'destroy'])->name('remarks.destroy');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        Route::get('/search', [SearchController::class, 'index'])->name('search.index');

        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::match(['put', 'post'], '/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');

        Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard.show');

        Route::prefix('reports')->group(function (): void {
            Route::get('/projects', [ReportController::class, 'projects'])->name('reports.projects.index');
            Route::get('/projects/{project}', [ReportController::class, 'project'])->name('reports.projects.show');
            Route::get('/employees', [ReportController::class, 'employees'])->name('reports.employees.index');
            Route::get('/employees/{user}', [ReportController::class, 'employee'])->name('reports.employees.show');
        });

        Route::prefix('lookups')->group(function (): void {
            Route::get('/roles', [LookupController::class, 'roles'])->name('lookups.roles');
            Route::get('/departments', [LookupController::class, 'departments'])->name('lookups.departments');
            Route::get('/job-titles', [LookupController::class, 'jobTitles'])->name('lookups.job-titles');
        });
    });
});
