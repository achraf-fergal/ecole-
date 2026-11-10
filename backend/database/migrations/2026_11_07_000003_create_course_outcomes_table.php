
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_outcomes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('course_id');

            /*
             * Outcomes are stored in their own locale.
             * The source locale is stored in the courses row.
             */
            $table->string('locale', 35);

            $table->string('content', 500);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->boolean('is_highlight')->default(false);

            $table->string('status', 20)->default('draft');

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(
                [
                    'center_id',
                    'course_id',
                    'locale',
                    'status',
                    'sort_order',
                ],
                'course_outcomes_publication_index'
            );

            $table->foreign(
                ['center_id', 'course_id'],
                'course_outcomes_center_course_fk'
            )
                ->references(['center_id', 'id'])
                ->on('courses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_outcomes');
    }
};
