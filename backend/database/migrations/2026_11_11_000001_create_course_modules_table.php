
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_modules', function (Blueprint $table) {
            $table->id();

            // Tenant ownership and parent course.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('course_id');

            // Educational module information.
            $table->string('title', 255);
            $table->text('description')->nullable();

            // Stable ordering within the course.
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Publication lifecycle.
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // Required for composite foreign keys from child tables.
            $table->unique(
                ['center_id', 'id'],
                'course_modules_center_id_unique'
            );

            // Efficient course module listing and publication filtering.
            $table->index(
                [
                    'center_id',
                    'course_id',
                    'status',
                    'published_at',
                    'sort_order',
                ],
                'course_modules_public_order_index'
            );

            // A module must belong to a course owned by the same center.
            $table->foreign(
                ['center_id', 'course_id'],
                'course_modules_center_course_fk'
            )
                ->references(['center_id', 'id'])
                ->on('courses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_modules');
    }
};
