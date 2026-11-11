
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_assets', function (Blueprint $table) {
            $table->id();

            // Tenant and parent lesson ownership.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('lesson_id');

            // General asset information.
            $table->string('title')->nullable();

            $table->string('asset_type', 20);

            // Private object storage metadata.
            $table->string('storage_disk', 50)->nullable();
            $table->string('storage_key', 1024)->nullable();

            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // External video provider metadata.
            $table->string('video_provider', 50)->nullable();
            $table->string('provider_asset_id', 255)->nullable();

            // External links only; never store signed playback URLs.
            $table->text('external_url')->nullable();

            // Media presentation.
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('alt_text')->nullable();
            $table->text('caption')->nullable();

            // Download and ordering settings.
            $table->boolean('is_downloadable')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Asset lifecycle.
            $table->string('status', 20)->default('active');

            $table->timestamps();

            // Required for future tenant-safe child relationships.
            $table->unique(
                ['center_id', 'id'],
                'lesson_assets_center_id_unique'
            );

            // Efficient asset listing and filtering.
            $table->index(
                [
                    'center_id',
                    'lesson_id',
                    'asset_type',
                    'status',
                    'sort_order',
                ],
                'lesson_assets_lesson_order_index'
            );

            // Prevent cross-tenant lesson references.
            $table->foreign(
                ['center_id', 'lesson_id'],
                'lesson_assets_center_lesson_fk'
            )
                ->references(['center_id', 'id'])
                ->on('lessons')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_assets');
    }
};
