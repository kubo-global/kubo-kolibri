<?php

namespace KuboKolibri\Console;

use Illuminate\Console\Command;
use KuboKolibri\Client\KolibriClient;
use KuboKolibri\Models\CurriculumMap;

/**
 * Fetch human-readable titles from Kolibri for curriculum_maps rows
 * that were created before the title column existed. New mappings
 * save the title at insert time; this is only for backfilling
 * pre-existing rows.
 *
 *   php artisan kolibri:backfill-map-titles [--force]
 *
 * --force re-fetches even rows that already have a title.
 */
class BackfillCurriculumMapTitles extends Command
{
    protected $signature = 'kolibri:backfill-map-titles {--force : Re-fetch titles for rows that already have one}';

    protected $description = 'Backfill curriculum_maps.title from Kolibri for rows missing it.';

    public function handle(KolibriClient $client): int
    {
        $query = CurriculumMap::query();
        if (!$this->option('force')) {
            $query->whereNull('title');
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info('Nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info("Backfilling {$total} curriculum_maps from Kolibri...");
        $bar = $this->output->createProgressBar($total);

        $updated = 0;
        $failed = 0;

        $query->chunkById(50, function ($maps) use ($client, $bar, &$updated, &$failed) {
            foreach ($maps as $map) {
                try {
                    $node = $client->getContentNode($map->kolibri_node_id);
                    $title = $node['title'] ?? null;
                    if ($title) {
                        $map->update(['title' => $title]);
                        $updated++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Updated: {$updated}, failed: {$failed}");

        return self::SUCCESS;
    }
}
