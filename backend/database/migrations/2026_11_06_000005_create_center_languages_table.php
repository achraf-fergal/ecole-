<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['center_id', 'language_id']);
            $table->index(['center_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_languages');
    }
};