
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();

            // Tenant and parent exercise ownership.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('exercise_id');

            // Question definition.
            $table->string('question_type', 20);

            $table->longText('prompt');

            // Hidden from student responses by default.
            $table->longText('explanation')->nullable();

            // Points awarded for a correct answer.
            $table->decimal('points', 8, 2)->default(1.00);

            // Display ordering within the exercise.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Required for tenant-safe child relationships.
            $table->unique(
                ['center_id', 'id'],
                'questions_center_id_unique'
            );

            // Efficient question listing.
            $table->index(
                ['center_id', 'exercise_id', 'sort_order'],
                'questions_exercise_order_index'
            );

            // Prevent cross-tenant exercise references.
            $table->foreign(
                ['center_id', 'exercise_id'],
                'questions_center_exercise_fk'
            )
                ->references(['center_id', 'id'])
                ->on('exercises')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
