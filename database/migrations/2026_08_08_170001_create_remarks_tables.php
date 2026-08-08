<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('remarkable_type');
            $table->unsignedBigInteger('remarkable_id');
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['remarkable_type', 'remarkable_id', 'created_at'], 'remarks_remarkable_created_index');
            $table->index('author_id', 'remarks_author_id_index');
        });

        Schema::create('remark_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remark_id')
                ->constrained('remarks')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['remark_id', 'user_id'], 'remark_mentions_remark_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remark_mentions');
        Schema::dropIfExists('remarks');
    }
};
