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
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('language_id');
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('study_format', 20)->default('in_person');
            $table->unsignedSmallInteger('duration_hours')->nullable();
            $table->string('cover_image_url', 2048)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->char('currency', 3)->default('MAD');
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['center_id', 'slug']);
            $table->index(['center_id', 'status', 'sort_order']);
            $table->index(['center_id', 'language_id', 'level_id']);

            $table->foreign(['center_id', 'language_id'])
                ->references(['center_id', 'id'])
                ->on('languages')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};