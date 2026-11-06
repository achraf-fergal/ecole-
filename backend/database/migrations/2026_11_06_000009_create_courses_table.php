<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('center_id')
                ->constrained('centers')
                ->restrictOnDelete();

            $table->unsignedBigInteger('language_id');
            $table->unsignedBigInteger('level_id');

            $table->string('title');
            $table->string('slug', 150);

            $table->string('short_description', 500)->nullable();

            $table->longText('description')->nullable();
            $table->text('prerequisites')->nullable();

            $table->string('study_format', 20)
                ->default('in_person');

            $table->unsignedSmallInteger('duration_hours')->nullable();

            // External URL for now; managed media storage comes later.
            $table->text('cover_image_url')->nullable();

            // Public price is optional.
            $table->decimal('price', 10, 2)->nullable();
            $table->char('currency', 3)->default('MAD');

            $table->string('status', 20)->default('draft');

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['center_id', 'slug'],
                'courses_center_slug_unique'
            );

            $table->index(
                ['center_id', 'status', 'published_at'],
                'courses_center_publication_index'
            );

            $table->index(
                ['center_id', 'language_id', 'level_id'],
                'courses_center_language_level_index'
            );

            // A course cannot use a language owned by another center.
            $table->foreign(
                ['center_id', 'language_id'],
                'courses_center_language_fk'
            )
                ->references(['center_id', 'id'])
                ->on('languages')
                ->restrictOnDelete();

            // A course cannot use a level owned by another center.
            $table->foreign(
                ['center_id', 'level_id'],
                'courses_center_level_fk'
            )
                ->references(['center_id', 'id'])
                ->on('levels')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
