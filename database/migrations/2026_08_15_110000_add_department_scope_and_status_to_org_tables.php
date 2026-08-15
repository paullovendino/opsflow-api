<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maps existing job_title.code → department.code for deterministic backfill.
     *
     * @var array<string, string>
     */
    private array $titleCodeToDepartmentCode = [
        'ADMIN' => 'ADMIN',
        'PM' => 'OPS',
        'SE' => 'ENG',
        'OPS_SPEC' => 'OPS',
        'HR_SPEC' => 'HR',
    ];

    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('status', 20)->default('active')->after('description');
        });

        Schema::table('job_titles', function (Blueprint $table): void {
            $table->string('status', 20)->default('active')->after('description');
            $table->foreignId('department_id')
                ->nullable()
                ->after('id')
                ->constrained('departments')
                ->restrictOnDelete();
        });

        $this->backfillJobTitleDepartments();
        $this->reconcileMismatchedUsers();

        Schema::table('job_titles', function (Blueprint $table): void {
            $table->dropUnique(['name']);
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropUnique(['name']);
        });

        DB::statement('ALTER TABLE job_titles ALTER COLUMN department_id SET NOT NULL');

        DB::statement('CREATE UNIQUE INDEX job_titles_department_name_lower_unique ON job_titles (department_id, LOWER(name)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX departments_name_lower_unique ON departments (LOWER(name)) WHERE deleted_at IS NULL');

        Schema::table('departments', function (Blueprint $table): void {
            $table->index('status');
        });

        Schema::table('job_titles', function (Blueprint $table): void {
            $table->index('status');
            $table->index('department_id');
        });

        // Allow admin-created rows without enum codes (keep existing codes).
        DB::statement('ALTER TABLE departments ALTER COLUMN code DROP NOT NULL');
        DB::statement('ALTER TABLE job_titles ALTER COLUMN code DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS job_titles_department_name_lower_unique');
        DB::statement('DROP INDEX IF EXISTS departments_name_lower_unique');

        Schema::table('job_titles', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['department_id']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn('status');
            $table->unique('name');
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
            $table->unique('name');
        });

        DB::statement('ALTER TABLE departments ALTER COLUMN code SET NOT NULL');
        DB::statement('ALTER TABLE job_titles ALTER COLUMN code SET NOT NULL');
    }

    private function backfillJobTitleDepartments(): void
    {
        $departmentIdsByCode = DB::table('departments')
            ->pluck('id', 'code')
            ->all();

        $titles = DB::table('job_titles')->select(['id', 'code', 'name'])->get();

        foreach ($titles as $title) {
            $code = (string) $title->code;
            $departmentCode = $this->titleCodeToDepartmentCode[$code] ?? null;

            if ($departmentCode === null || ! isset($departmentIdsByCode[$departmentCode])) {
                throw new RuntimeException(
                    "Unable to map job title [{$title->id}:{$title->name}:{$code}] to a department during migration.",
                );
            }

            DB::table('job_titles')
                ->where('id', $title->id)
                ->update(['department_id' => $departmentIdsByCode[$departmentCode]]);
        }

        $unmapped = DB::table('job_titles')->whereNull('department_id')->count();
        if ($unmapped > 0) {
            throw new RuntimeException("{$unmapped} job title(s) still missing department_id after backfill.");
        }
    }

    private function reconcileMismatchedUsers(): void
    {
        // Preserve department_id; clear job_title_id when title.department_id <> user.department_id
        // or when department is null but title is set.
        DB::table('users')
            ->whereNotNull('job_title_id')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $title = DB::table('job_titles')->where('id', $user->job_title_id)->first();
                    if ($title === null) {
                        DB::table('users')->where('id', $user->id)->update(['job_title_id' => null]);

                        continue;
                    }

                    $titleDepartmentId = $title->department_id !== null ? (int) $title->department_id : null;
                    $userDepartmentId = $user->department_id !== null ? (int) $user->department_id : null;

                    if ($userDepartmentId === null || $titleDepartmentId !== $userDepartmentId) {
                        DB::table('users')->where('id', $user->id)->update(['job_title_id' => null]);
                    }
                }
            });
    }
};
