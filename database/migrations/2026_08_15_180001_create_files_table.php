<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('disk', 32)->default('public');
            $table->string('mime_type', 127);
            $table->string('extension', 32);
            $table->unsignedBigInteger('size');
            $table->string('collection', 64);
            $table->morphs('attachable');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
