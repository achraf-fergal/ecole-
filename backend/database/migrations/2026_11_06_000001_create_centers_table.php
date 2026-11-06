<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centers', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug', 100)->unique();

            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('logo_url', 2048)->nullable();

            $table->string('status', 20)->default('active');

            $table->string('timezone', 64)
                ->default('Africa/Casablanca');

            $table->string('default_locale', 5)
                ->default('en');

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('centers');
    }
};
