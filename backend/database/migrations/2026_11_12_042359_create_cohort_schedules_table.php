
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohort_schedules', function (Blueprint $table) {
            $table->id();

            // Tenant and parent cohort ownership.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('cohort_id');

            // ISO weekday: Monday = 1, Sunday = 7.
            $table->unsignedTinyInteger('day_of_week');

            // Recurring local wall-clock times.
            $table->time('start_time');
            $table->time('end_time');

            // Optional location override for this session.
            $table->string('location_label', 150)->nullable();

            $table->timestamps();

            /*
             * Prevent duplicate start times on the same day
             * within the same cohort.
             */
            $table->unique(
                [
                    'center_id',
                    'cohort_id',
                    'day_of_week',
                    'start_time',
                ],
                'cohort_schedules_slot_unique'
            );

            /*
             * Useful for searching schedules across cohorts
             * within the same center.
             */
            $table->index(
                [
                    'center_id',
                    'day_of_week',
                    'start_time',
                ],
                'cohort_schedules_center_day_time_index'
            );

            // A schedule must belong to a cohort in the same center.
            $table->foreign(
                ['center_id', 'cohort_id'],
                'cohort_schedules_center_cohort_fk'
            )
                ->references(['center_id', 'id'])
                ->on('cohorts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cohort_schedules');
    }
};
