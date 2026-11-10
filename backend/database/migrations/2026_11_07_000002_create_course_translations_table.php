
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_translations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('course_id');

            $table->string('locale', 35);

            // Required localized title.
            $table->string('title');

            $table->string('short_description', 500)->nullable();

            $table->longText('description')->nullable();

            $table->text('prerequisites')->nullable();

            $table->text('target_audience')->nullable();

            $table->string('meta_title', 70)->nullable();

            $table->string('meta_description', 170)->nullable();

            $table->string('cover_image_alt', 255)->nullable();

            $table->string('status', 20)->default('draft');

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            /*
             * One translation per course and locale
             * inside the same center.
             */
            $table->unique(
                ['center_id', 'course_id', 'locale'],
                'course_translations_course_locale_unique'
            );

            $table->index(
                ['center_id', 'locale', 'status', 'published_at'],
                'course_translations_publication_index'
            );

            /*
             * The center and course must belong together.
             */
            $table->foreign(
                ['center_id', 'course_id'],
                'course_translations_center_course_fk'
            )
                ->references(['center_id', 'id'])
                ->on('courses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_translations');
    }
};
