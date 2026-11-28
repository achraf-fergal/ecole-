<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table): void {
            $table->decimal('total_points', 10, 2)->default(1.00)->after('passing_score');
        });

        DB::table('exercises')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($exercises): void {
                $exerciseIds = $exercises->pluck('id')->all();
                $totals = DB::table('questions')
                    ->whereIn('exercise_id', $exerciseIds)
                    ->select('exercise_id')
                    ->selectRaw('SUM(points) as total_points')
                    ->groupBy('exercise_id')
                    ->pluck('total_points', 'exercise_id');

                foreach ($exercises as $exercise) {
                    $total = number_format((float) ($totals[$exercise->id] ?? 0), 2, '.', '');
                    DB::table('exercises')
                        ->where('id', $exercise->id)
                        ->update(['total_points' => $total]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table): void {
            $table->dropColumn('total_points');
        });
    }
};
