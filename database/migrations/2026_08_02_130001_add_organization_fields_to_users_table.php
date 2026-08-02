<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable()->after('id');
            $table->unsignedBigInteger('department_id')->nullable()->after('role_id');
            $table->unsignedBigInteger('job_title_id')->nullable()->after('department_id');
            $table->string('first_name')->nullable()->after('job_title_id');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('avatar')->nullable()->after('password');
            $table->string('status')->default(UserStatus::Active->value)->after('avatar');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->softDeletes()->after('updated_at');
        });

        $employeeRoleId = $this->resolveEmployeeRoleId();

        DB::table('users')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $user) use ($employeeRoleId): void {
                [$firstName, $middleName, $lastName] = $this->splitName((string) $user->name);

                DB::table('users')->where('id', $user->id)->update([
                    'role_id' => $employeeRoleId,
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'last_name' => $lastName,
                    'status' => UserStatus::Active->value,
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });

        DB::statement('ALTER TABLE users ALTER COLUMN first_name SET NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN role_id SET NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->restrictOnDelete();

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();

            $table->foreign('job_title_id')
                ->references('id')
                ->on('job_titles')
                ->restrictOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['department_id']);
            $table->dropForeign(['job_title_id']);
            $table->dropIndex(['status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });

        DB::table('users')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $user): void {
                DB::table('users')->where('id', $user->id)->update([
                    'name' => $this->composeName(
                        (string) $user->first_name,
                        $user->middle_name !== null ? (string) $user->middle_name : null,
                        $user->last_name !== null ? (string) $user->last_name : null,
                    ),
                ]);
            });

        DB::statement('ALTER TABLE users ALTER COLUMN name SET NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role_id',
                'department_id',
                'job_title_id',
                'first_name',
                'middle_name',
                'last_name',
                'avatar',
                'status',
                'last_login_at',
                'deleted_at',
            ]);
        });
    }

    private function resolveEmployeeRoleId(): int
    {
        $roleId = DB::table('roles')
            ->where('name', RoleName::Employee->value)
            ->value('id');

        if ($roleId !== null) {
            return (int) $roleId;
        }

        return (int) DB::table('roles')->insertGetId([
            'name' => RoleName::Employee->value,
            'description' => RoleName::Employee->description(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function splitName(string $name): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        if ($normalized === '') {
            return ['User', null, null];
        }

        $parts = explode(' ', $normalized);

        if (count($parts) === 1) {
            return [$parts[0], null, null];
        }

        if (count($parts) === 2) {
            return [$parts[0], null, $parts[1]];
        }

        $firstName = array_shift($parts);
        $lastName = array_pop($parts);
        $middleName = implode(' ', $parts);

        return [$firstName, $middleName !== '' ? $middleName : null, $lastName];
    }

    private function composeName(string $firstName, ?string $middleName, ?string $lastName): string
    {
        if ($lastName === null || $lastName === '') {
            return $firstName;
        }

        if ($middleName !== null && $middleName !== '') {
            return trim("{$firstName} {$middleName} {$lastName}");
        }

        return trim("{$firstName} {$lastName}");
    }
};
