<?php

namespace App\Console\Commands;

use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MigrateLegacyLessonContent extends Command
{
    protected $signature = 'learning:migrate-legacy-content
                            {--dry-run : Preview migration without writing data}';

    protected $description = 'Migrate legacy lesson bodies into versioned lesson blocks';

    public function handle(): int
    {
        $lessons = Lesson::query()
            ->orderBy('center_id')
            ->orderBy('id')
            ->get();

        if ($lessons->isEmpty()) {
            $this->info('No lessons found.');

            return self::SUCCESS;
        }

        $eligible = [];
        $blockers = [];
        $rows = [];

        foreach ($lessons as $lesson) {
            $hasRevision = LessonRevision::query()
                ->where('center_id', $lesson->center_id)
                ->where('lesson_id', $lesson->id)
                ->exists();

            $alreadyMigrated = $hasRevision
                || $lesson->draft_revision_id !== null
                || $lesson->published_revision_id !== null;

            $assetCount = LessonAsset::query()
                ->where('center_id', $lesson->center_id)
                ->where('lesson_id', $lesson->id)
                ->count();

            $exerciseCount = Exercise::query()
                ->where('center_id', $lesson->center_id)
                ->where('lesson_id', $lesson->id)
                ->count();

            $body = (string) $lesson->body;

            $reason = null;

            if ($alreadyMigrated) {
                $result = 'Already migrated';
            } elseif (
                ! in_array(
                    $lesson->status,
                    [
                        Lesson::STATUS_DRAFT,
                        Lesson::STATUS_PUBLISHED,
                    ],
                    true
                )
            ) {
                $reason = 'Unsupported lesson status.';
            } elseif (
                $lesson->status === Lesson::STATUS_PUBLISHED
                && $lesson->published_at === null
            ) {
                $reason = 'Published lesson has no published_at.';
            } elseif ($assetCount > 0 || $exerciseCount > 0) {
                $reason = 'Legacy assets or exercises require manual ordering review.';
            } elseif (trim($body) === '') {
                $reason = 'Lesson body is empty.';
            } elseif (
                preg_match('/<\/?[a-z][^>]*>/i', $body) === 1
            ) {
                $reason = 'HTML content requires a dedicated conversion.';
            } elseif (! mb_check_encoding($body, 'UTF-8')) {
                $reason = 'Invalid UTF-8 content.';
            }

            if ($reason !== null) {
                $result = 'BLOCKED: '.$reason;
                $blockers[] = $lesson->id;
            } elseif (! $alreadyMigrated) {
                $result = 'Eligible';
                $eligible[] = [
                    'id' => $lesson->id,
                    'center_id' => $lesson->center_id,
                ];
            }

            $rows[] = [
                $lesson->id,
                $lesson->center_id,
                $lesson->title,
                $lesson->status,
                $assetCount,
                $exerciseCount,
                $result,
            ];
        }

        $this->table(
            [
                'Lesson ID',
                'Center ID',
                'Title',
                'Status',
                'Assets',
                'Exercises',
                'Result',
            ],
            $rows
        );

        $this->info(
            'Eligible lessons: '.count($eligible)
        );

        $this->info(
            'Already migrated: '.(
                $lessons->count()
                - count($eligible)
                - count($blockers)
            )
        );

        if (count($blockers) > 0) {
            $this->error(
                'Migration blocked. Review the affected lessons first.'
            );

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run completed. No database records were changed.');

            return self::SUCCESS;
        }

        if (count($eligible) === 0) {
            $this->info('Nothing to migrate.');

            return self::SUCCESS;
        }

        if (! $this->confirm(
            'Create initial revisions and migrate the eligible lesson bodies?'
        )) {
            $this->warn('Migration cancelled.');

            return self::SUCCESS;
        }

        $migrated = 0;

        foreach ($eligible as $item) {
            DB::transaction(function () use ($item, &$migrated): void {
                $lesson = Lesson::query()
                    ->where('center_id', $item['center_id'])
                    ->whereKey($item['id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                // Recheck idempotency after acquiring the lock.
                $exists = LessonRevision::query()
                    ->where('center_id', $lesson->center_id)
                    ->where('lesson_id', $lesson->id)
                    ->exists();

                if (
                    $exists
                    || $lesson->draft_revision_id !== null
                    || $lesson->published_revision_id !== null
                ) {
                    return;
                }

                // Recheck media and exercise counts inside transaction.
                $hasAssets = LessonAsset::query()
                    ->where('center_id', $lesson->center_id)
                    ->where('lesson_id', $lesson->id)
                    ->exists();

                $hasExercises = Exercise::query()
                    ->where('center_id', $lesson->center_id)
                    ->where('lesson_id', $lesson->id)
                    ->exists();

                if ($hasAssets || $hasExercises) {
                    throw new RuntimeException(
                        "Lesson {$lesson->id} gained media or exercises during migration."
                    );
                }

                $body = (string) $lesson->body;

                if (
                    trim($body) === ''
                    || preg_match('/<\/?[a-z][^>]*>/i', $body) === 1
                    || ! mb_check_encoding($body, 'UTF-8')
                ) {
                    throw new RuntimeException(
                        "Lesson {$lesson->id} content changed or is unsupported."
                    );
                }

                $isPublished =
                    $lesson->status === Lesson::STATUS_PUBLISHED;

                $revision = LessonRevision::query()->create([
                    'center_id' => $lesson->center_id,
                    'lesson_id' => $lesson->id,

                    'version_number' => 1,

                    'status' => $isPublished
                        ? LessonRevision::STATUS_PUBLISHED
                        : LessonRevision::STATUS_DRAFT,

                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'content_locale' => $lesson->content_locale,
                    'duration_minutes' => $lesson->duration_minutes,

                    // Original author information is unavailable.
                    'created_by_user_id' => null,

                    // Preserve legacy publication time.
                    'published_by_user_id' => null,
                    'published_at' => $isPublished
                        ? $lesson->published_at
                        : null,
                ]);

                LessonBlock::query()->create([
                    'center_id' => $lesson->center_id,
                    'lesson_id' => $lesson->id,
                    'lesson_revision_id' => $revision->id,

                    'block_type' => LessonBlock::TYPE_TEXT,
                    'sort_order' => 0,

                    'content_json' => $this->toTipTapDocument($body),

                    'lesson_asset_id' => null,
                    'exercise_id' => null,
                    'settings' => null,
                ]);

                // Update the appropriate current revision pointer.
                $lesson->forceFill([
                    'draft_revision_id' => $isPublished
                        ? null
                        : $revision->id,

                    'published_revision_id' => $isPublished
                        ? $revision->id
                        : null,
                ])->save();

                $migrated++;
            });
        }

        $this->info("Successfully migrated {$migrated} lessons.");

        return self::SUCCESS;
    }

    /**
     * Convert legacy plaintext into a TipTap-compatible document.
     *
     * Empty lines separate paragraphs.
     * Single line breaks become hardBreak nodes.
     */
    private function toTipTapDocument(string $body): array
    {
        $normalized = str_replace(
            ["\r\n", "\r"],
            "\n",
            trim($body)
        );

        $paragraphs = preg_split(
            '/\n[ \t]*\n+/u',
            $normalized
        ) ?: [];

        $content = [];

        foreach ($paragraphs as $paragraphText) {
            $lines = explode("\n", $paragraphText);

            $paragraphContent = [];

            foreach ($lines as $index => $line) {
                if ($index > 0) {
                    $paragraphContent[] = [
                        'type' => 'hardBreak',
                    ];
                }

                if ($line !== '') {
                    $paragraphContent[] = [
                        'type' => 'text',
                        'text' => $line,
                    ];
                }
            }

            $node = [
                'type' => 'paragraph',
            ];

            if ($paragraphContent !== []) {
                $node['content'] = $paragraphContent;
            }

            $content[] = $node;
        }

        if ($content === []) {
            $content[] = [
                'type' => 'paragraph',
            ];
        }

        return [
            'type' => 'doc',
            'content' => $content,
        ];
    }
}
