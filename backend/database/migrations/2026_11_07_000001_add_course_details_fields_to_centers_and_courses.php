<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Support locale tags such as en, fr, fr-MA and ar-MA.
         */
        Schema::table('centers', function (Blueprint $table) {
            $table->string('default_locale', 35)
                ->default('en')
                ->change();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->string('source_locale', 35)
                ->default('en');

            $table->text('target_audience')->nullable();

            $table->string('pricing_mode', 20)
                ->default('upon_request');

            $table->boolean('is_curriculum_public')
                ->default(false);

            $table->string('meta_title', 70)->nullable();

            $table->string('meta_description', 170)->nullable();

            $table->string('cover_image_alt', 255)->nullable();

            /*
             * Required as a referenced key for tenant-safe
             * composite foreign keys in translation/outcome tables.
             */
            $table->unique(
                ['center_id', 'id'],
                'courses_center_id_unique'
            );
        });

        /*
         * Preserve each existing course's original locale.
         * Existing prices are classified without inventing amounts.
         */
        DB::table('courses')
            ->select('id', 'center_id', 'price')
            ->orderBy('id')
            ->chunkById(200, function ($courses): void {
                $centerIds = $courses->pluck('center_id')
                    ->unique()
                    ->values();

                $locales = DB::table('centers')
                    ->whereIn('id', $centerIds)
                    ->pluck('default_locale', 'id');

                foreach ($courses as $course) {
                    $price = $course->price;

                    $pricingMode = match (true) {
                        $price === null => 'upon_request',
                        (float) $price === 0.0 => 'free',
                        (float) $price > 0 => 'fixed',
                        default => 'upon_request',
                    };

                    DB::table('courses')
                        ->where('id', $course->id)
                        ->update([
                            'source_locale' => $locales[$course->center_id] ?? 'en',

                            'pricing_mode' => $pricingMode,
                        ]);
                }
            });
    }

    public function down(): void
    {
        /*
         * Do not silently truncate future locale tags.
         */
        $hasLongLocales = DB::table('centers')
            ->whereRaw('LENGTH(default_locale) > 5')
            ->exists();

        if ($hasLongLocales) {
            throw new RuntimeException(
                'Rollback blocked: one or more center locales exceed 5 characters. '
                .'Review and migrate these values before rolling back.'
            );
        }

        Schema::table('courses', function (Blueprint $table) {
            $table->dropUnique('courses_center_id_unique');

            $table->dropColumn([
                'source_locale',
                'target_audience',
                'pricing_mode',
                'is_curriculum_public',
                'meta_title',
                'meta_description',
                'cover_image_alt',
            ]);
        });

        Schema::table('centers', function (Blueprint $table) {
            $table->string('default_locale', 5)
                ->default('en')
                ->change();
        });
    }
};
