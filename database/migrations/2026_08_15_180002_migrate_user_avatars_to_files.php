<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar')) {
            return;
        }

        $now = now();

        DB::table('users')
            ->whereNotNull('avatar')
            ->where('avatar', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($now): void {
                foreach ($users as $user) {
                    $path = (string) $user->avatar;

                    if (! str_starts_with($path, 'avatars/')) {
                        continue;
                    }

                    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
                    $mime = match ($extension) {
                        'jpg', 'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'webp' => 'image/webp',
                        default => 'application/octet-stream',
                    };

                    $size = 0;
                    if (Storage::disk('public')->exists($path)) {
                        $size = (int) Storage::disk('public')->size($path);
                    }

                    $basename = basename($path);
                    $originalName = str_starts_with($basename, 'avatar.')
                        ? 'avatar.'.$extension
                        : $basename;

                    DB::table('files')->insert([
                        'file_name' => $originalName,
                        'file_path' => $path,
                        'disk' => 'public',
                        'mime_type' => $mime,
                        'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
                        'size' => $size,
                        'collection' => 'avatar',
                        'attachable_type' => 'user',
                        'attachable_id' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'avatar')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('avatar')->nullable()->after('password');
            });
        }

        if (! Schema::hasTable('files')) {
            return;
        }

        $avatars = DB::table('files')
            ->where('collection', 'avatar')
            ->where('attachable_type', 'user')
            ->orderBy('id')
            ->get();

        foreach ($avatars as $file) {
            DB::table('users')
                ->where('id', $file->attachable_id)
                ->update(['avatar' => $file->file_path]);
        }

        DB::table('files')
            ->where('collection', 'avatar')
            ->where('attachable_type', 'user')
            ->delete();
    }
};
