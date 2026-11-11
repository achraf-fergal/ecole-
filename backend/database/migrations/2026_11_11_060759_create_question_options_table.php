
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_options', function (Blueprint $table) {
            $table->id();

            // Tenant and parent question ownership.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('question_id');

            // Option information.
            $table->text('option_text');

            // Answer key. Hidden from normal JSON serialization.
            $table->boolean('is_correct')->default(false);

            // Display ordering within the question.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Required for tenant-safe child relationships.
            $table->unique(
                ['center_id', 'id'],
                'question_options_center_id_unique'
            );

            // Important for future answers:
            // guarantees that a selected option belongs to its question.
            $table->unique(
                ['center_id', 'question_id', 'id'],
                'question_options_question_identity_unique'
            );

            // Efficient ordered option retrieval.
            $table->index(
                ['center_id', 'question_id', 'sort_order'],
                'question_options_question_order_index'
            );

            // Prevent cross-tenant question references.
            $table->foreign(
                ['center_id', 'question_id'],
                'question_options_center_question_fk'
            )
                ->references(['center_id', 'id'])
                ->on('questions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
    }
};
