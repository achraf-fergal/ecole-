
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_revisions', function (Blueprint $table) {
            $table->id();

            // Tenant and parent lesson.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('lesson_id');

            // Revision identity.
            $table->unsignedInteger('version_number');

            // draft | published | superseded
            $table->string('status', 20)->default('draft');

            // Immutable lesson metadata snapshot.
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('content_locale', 35);
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            // Revision authorship.
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();

            // Publication metadata.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // Composite identity for tenant-safe references.
            $table->unique(
                ['center_id', 'lesson_id', 'id'],
                'lesson_revisions_lesson_identity_unique'
            );

            // Version number cannot repeat within a lesson.
            $table->unique(
                ['center_id', 'lesson_id', 'version_number'],
                'lesson_revisions_version_unique'
            );

            $table->index(
                ['center_id', 'lesson_id', 'status'],
                'lesson_revisions_status_index'
            );

            // Revision must belong to the exact center and lesson.
            $table->foreign(
                ['center_id', 'lesson_id'],
                'lesson_revisions_center_lesson_fk'
            )
                ->references(['center_id', 'id'])
                ->on('lessons')
                ->restrictOnDelete();

            $table->foreign('created_by_user_id', 'lesson_revisions_created_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('published_by_user_id', 'lesson_revisions_published_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        // Add explicit current revision pointers to lessons.
        Schema::table('lessons', function (Blueprint $table) {
            $table->unsignedBigInteger('draft_revision_id')->nullable();

            $table->unsignedBigInteger('published_revision_id')->nullable();

            $table->index(
                ['center_id', 'id', 'draft_revision_id'],
                'lessons_draft_revision_index'
            );

            $table->index(
                ['center_id', 'id', 'published_revision_id'],
                'lessons_published_revision_index'
            );

            $table->foreign(
                ['center_id', 'id', 'draft_revision_id'],
                'lessons_draft_revision_fk'
            )
                ->references(['center_id', 'lesson_id', 'id'])
                ->on('lesson_revisions')
                ->restrictOnDelete();

            $table->foreign(
                ['center_id', 'id', 'published_revision_id'],
                'lessons_published_revision_fk'
            )
                ->references(['center_id', 'lesson_id', 'id'])
                ->on('lesson_revisions')
                ->restrictOnDelete();
        });

        // MySQL 8.0.43 enforces CHECK constraints.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE lesson_revisions
                ADD CONSTRAINT lesson_revisions_status_check
                CHECK (
                    status IN (
                        'draft',
                        'published',
                        'superseded'
                    )
                )
            ");
        }
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign('lessons_draft_revision_fk');
            $table->dropForeign('lessons_published_revision_fk');

            $table->dropIndex('lessons_draft_revision_index');
            $table->dropIndex('lessons_published_revision_index');

            $table->dropColumn([
                'draft_revision_id',
                'published_revision_id',
            ]);
        });

        Schema::dropIfExists('lesson_revisions');
    }
};
