<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('course_module_id');

            $table->string('title');

            $table->text('description')->nullable();

            $table->string('content_type', 20)->default('text');

            $table->longText('body')->nullable();

            $table->string('content_locale', 35);

            $table->unsignedSmallInteger('duration_minutes')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->string('status', 20)->default('draft');

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            /*
             * Required for composite foreign keys
             * from tenant-owned child tables.
             */
            $table->unique(
                ['center_id', 'id'],
                'lessons_center_id_unique'
            );

            $table->index(
                [
                    'center_id',
                    'course_module_id',
                    'status',
                    'published_at',
                    'sort_order',
                ],
                'lessons_module_publication_index'
            );

            /*
             * A lesson must belong to a module
             * owned by the same center.
             */
            $table->foreign(
                ['center_id', 'course_module_id'],
                'lessons_center_module_fk'
            )
                ->references(['center_id', 'id'])
                ->on('course_modules')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};