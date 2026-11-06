<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('center_id')
                ->constrained('centers')
                ->restrictOnDelete();

            $table->foreignId('level_template_id')
                ->nullable()
                ->constrained('level_templates')
                ->restrictOnDelete();

            $table->string('code', 40);
            $table->string('name');

            $table->text('description')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // A level code must be unique inside its center.
            $table->unique(
                ['center_id', 'code'],
                'levels_center_code_unique'
            );

            // A template can be activated only once per center.
            // Multiple custom levels may have a NULL template ID.
            $table->unique(
                ['center_id', 'level_template_id'],
                'levels_center_template_unique'
            );

            // Required for the courses composite foreign key.
            $table->unique(
                ['center_id', 'id'],
                'levels_center_id_unique'
            );

            $table->index(
                ['center_id', 'is_active', 'sort_order'],
                'levels_center_active_sort_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};
