
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_blocks', function (Blueprint $table) {
            $table->id();

            // Explicit tenant and lesson ownership.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('lesson_id');

            // Exact content revision.
            $table->unsignedBigInteger('lesson_revision_id');

            // text | media | exercise
            $table->string('block_type', 20);

            // Position in the lesson revision.
            $table->unsignedInteger('sort_order')->default(0);

            // Structured rich-text document (TipTap/ProseMirror JSON).
            $table->json('content_json')->nullable();

            // Media reference.
            $table->unsignedBigInteger('lesson_asset_id')->nullable();

            // Exercise reference.
            $table->unsignedBigInteger('exercise_id')->nullable();

            // Presentation options.
            $table->json('settings')->nullable();

            $table->timestamps();

            // Fast ordered content retrieval.
            $table->index(
                [
                    'center_id',
                    'lesson_revision_id',
                    'sort_order',
                    'id',
                ],
                'lesson_blocks_revision_order_index'
            );

            // Revision must belong to the exact lesson and center.
            $table->foreign(
                [
                    'center_id',
                    'lesson_id',
                    'lesson_revision_id',
                ],
                'lesson_blocks_revision_fk'
            )
                ->references([
                    'center_id',
                    'lesson_id',
                    'id',
                ])
                ->on('lesson_revisions')
                ->restrictOnDelete();

            // Asset must belong to the exact lesson and center.
            $table->foreign(
                [
                    'center_id',
                    'lesson_id',
                    'lesson_asset_id',
                ],
                'lesson_blocks_asset_fk'
            )
                ->references([
                    'center_id',
                    'lesson_id',
                    'id',
                ])
                ->on('lesson_assets')
                ->restrictOnDelete();

            // Exercise must belong to the exact lesson and center.
            $table->foreign(
                [
                    'center_id',
                    'lesson_id',
                    'exercise_id',
                ],
                'lesson_blocks_exercise_fk'
            )
                ->references([
                    'center_id',
                    'lesson_id',
                    'id',
                ])
                ->on('exercises')
                ->restrictOnDelete();
        });

        // Enforce block payload consistency on MySQL.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE lesson_blocks
                ADD CONSTRAINT lesson_blocks_type_check
                CHECK (
                    block_type IN (
                        'text',
                        'media',
                        'exercise'
                    )
                )
            ");

            DB::statement("
                ALTER TABLE lesson_blocks
                ADD CONSTRAINT lesson_blocks_payload_check
                CHECK (
                    (
                        block_type = 'text'
                        AND content_json IS NOT NULL
                        AND lesson_asset_id IS NULL
                        AND exercise_id IS NULL
                    )
                    OR
                    (
                        block_type = 'media'
                        AND content_json IS NULL
                        AND lesson_asset_id IS NOT NULL
                        AND exercise_id IS NULL
                    )
                    OR
                    (
                        block_type = 'exercise'
                        AND content_json IS NULL
                        AND lesson_asset_id IS NULL
                        AND exercise_id IS NOT NULL
                    )
                )
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_blocks');
    }
};
