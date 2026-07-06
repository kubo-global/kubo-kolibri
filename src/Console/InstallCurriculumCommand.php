<?php

namespace KuboKolibri\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use KuboKolibri\Services\CurriculumInstaller;

/**
 * CLI front for CurriculumInstaller: install a bundled curriculum (skill
 * graph + Kolibri content mappings) so a fresh install has a working /learn
 * surface without hand-mapping content first. Idempotent; also available to
 * headmasters from Settings.
 */
class InstallCurriculumCommand extends Command
{
    protected $signature = 'kolibri:install-curriculum
        {curriculum? : Curriculum slug, e.g. gambia-mathematics (see --list)}
        {--list : List the bundled curricula}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Install a bundled curriculum (skills, prerequisites, Kolibri mappings) into this school (idempotent).';

    public function handle(CurriculumInstaller $installer): int
    {
        if ($this->option('list') || !$this->argument('curriculum')) {
            return $this->listCurricula($installer);
        }

        $slug = $this->argument('curriculum');
        $data = $installer->load($slug);
        if (!$data) {
            $this->error("Unknown curriculum '{$slug}'. Use --list to see what is bundled.");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info("Installing '{$data['name']}'" . ($dryRun ? ' [dry-run]' : ''));

        try {
            $stats = $installer->install($data, $dryRun);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Skills + maps: %d created, %d updated, %d unchanged. Edges: %d. Content links: %d.%s',
            $stats['created'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['edges'],
            $stats['links'],
            $dryRun ? ' (rolled back)' : '',
        ));
        $this->line('Content must also exist in Kolibri — run kolibri:check-content to verify the channel is imported.');

        return self::SUCCESS;
    }

    private function listCurricula(CurriculumInstaller $installer): int
    {
        $curricula = $installer->available();
        if (!$curricula) {
            $this->info('No bundled curricula found.');
            return self::SUCCESS;
        }
        $rows = [];
        foreach ($curricula as $slug => $c) {
            $rows[] = [$slug, $c['name'], $c['subject'], $c['skills'], $c['maps'], $c['state']];
        }
        $this->table(['slug', 'name', 'subject', 'skills', 'maps', 'status'], $rows);
        $this->line('Install one with: php artisan kolibri:install-curriculum <slug>');

        return self::SUCCESS;
    }
}
