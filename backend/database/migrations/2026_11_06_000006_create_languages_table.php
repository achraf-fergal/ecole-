<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('center_id')
                ->constrained('centers')
                ->restrictOnDelete();

            $table->string('code', 10);
            $table->string('name');
            $table->string('native_name')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(
                ['center_id', 'code'],
                'languages_center_code_unique'
            );

            // Required as a referenced composite key by courses.
            $table->unique(
                ['center_id', 'id'],
                'languages_center_id_unique'
            );

            $table->index(
                ['center_id', 'is_active', 'sort_order'],
                'languages_center_active_sort_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
