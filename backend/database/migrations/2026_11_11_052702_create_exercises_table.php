
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();

            // Tenant and parent lesson ownership.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('lesson_id');

            // Exercise information.
            $table->string('title', 255);
            $table->text('instructions')->nullable();

            // Assessment configuration.
            // NULL means unlimited attempts.
            $table->unsignedSmallInteger('max_attempts')->nullable();

            // Passing score is a percentage from 0 to 100.
            $table->decimal('passing_score', 5, 2)->default(60.00);

            // Correct answers remain hidden by default.
            $table->boolean(
                'show_correct_answers_after_submission'
            )->default(false);

            // Display order within the lesson.
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Publication lifecycle.
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // Required for tenant-safe child relationships.
            $table->unique(
                ['center_id', 'id'],
                'exercises_center_id_unique'
            );

            // Efficient lesson exercise listing.
            $table->index(
                [
                    'center_id',
                    'lesson_id',
                    'status',
                    'published_at',
                    'sort_order',
                ],
                'exercises_lesson_publication_index'
            );

            // Ensure exercise and lesson belong to the same center.
            $table->foreign(
                ['center_id', 'lesson_id'],
                'exercises_center_lesson_fk'
            )
                ->references(['center_id', 'id'])
                ->on('lessons')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
