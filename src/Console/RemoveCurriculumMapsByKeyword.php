<?php

namespace KuboKolibri\Console;

use Illuminate\Console\Command;
use KuboKolibri\Models\CurriculumMap;

/**
 * Remove curriculum_maps rows whose title matches one of the given keywords.
 * Default keyword set covers US imperial units and US currency — Kolibri's
 * open content has plenty of these, irrelevant for a Gambian school
 * working in metric and dalasi.
 *
 *   php artisan kolibri:remove-maps-by-keyword                       # dry-run, default keywords
 *   php artisan kolibri:remove-maps-by-keyword --apply               # delete
 *   php artisan kolibri:remove-maps-by-keyword inch pound --apply    # custom keyword set
 *
 * Cascades through skill_content + exercise_runs (cascadeOnDelete is set
 * on both pivots). The actual Kolibri content node is untouched —
 * unmapping just removes it from KUBO's curriculum.
 */
class RemoveCurriculumMapsByKeyword extends Command
{
    protected $signature = 'kolibri:remove-maps-by-keyword
        {keywords?* : Keywords to match against title (case-insensitive). Defaults if omitted.}
        {--apply : Actually delete (default is dry-run)}';

    protected $description = 'Remove curriculum_maps whose title matches given keywords (defaults to US imperial units + USD).';

    private const DEFAULT_KEYWORDS = [
        // US imperial length
        'inch', 'inches', 'foot', 'feet', 'yard', 'yards', 'mile', 'miles',
        // US imperial weight
        'pound', 'pounds', 'ounce', 'ounces',
        // US imperial volume
        'gallon', 'gallons', 'quart', 'quarts', 'pint', 'pints',
        // Temperature
        'fahrenheit',
        // US currency
        'dollar', 'dollars',
    ];

    public function handle(): int
    {
        $keywords = $this->argument('keywords');
        if (empty($keywords)) {
            $keywords = self::DEFAULT_KEYWORDS;
            $this->line('Using default keywords (US imperial + USD).');
        }

        // Word-boundary match so "cents" doesn't catch "percents",
        // "pound" doesn't catch "compound", "ounce" doesn't catch
        // "announce", etc. MariaDB/MySQL syntax: [[:<:]] / [[:>:]].
        $pattern = '[[:<:]](' . implode('|', array_map(
            fn ($k) => preg_quote($k, '/'),
            $keywords
        )) . ')[[:>:]]';

        $query = CurriculumMap::query()
            ->whereNotNull('title')
            ->whereRaw('title REGEXP ?', [$pattern]);

        $matches = $query->orderBy('title')->get(['id', 'title', 'content_kind']);

        if ($matches->isEmpty()) {
            $this->info('No matching maps.');
            return self::SUCCESS;
        }

        $this->info("Matching {$matches->count()} maps:");
        foreach ($matches as $m) {
            $this->line("  [{$m->id}] {$m->title}");
        }

        if (!$this->option('apply')) {
            $this->newLine();
            $this->warn('Dry-run. Re-run with --apply to delete.');
            return self::SUCCESS;
        }

        $deleted = CurriculumMap::whereIn('id', $matches->pluck('id'))->delete();
        $this->newLine();
        $this->info("Deleted {$deleted} curriculum_maps (skill_content + exercise_runs cascaded).");

        return self::SUCCESS;
    }
}
