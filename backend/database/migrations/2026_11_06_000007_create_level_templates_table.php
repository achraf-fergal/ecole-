<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_templates', function (Blueprint $table) {
            $table->id();

            $table->string('code', 40)->unique();
            $table->string('name');

            $table->text('description')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(
                ['is_active', 'sort_order'],
                'level_templates_active_sort_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_templates');
    }
};
