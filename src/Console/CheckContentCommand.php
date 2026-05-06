<?php

namespace KuboKolibri\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use KuboKolibri\Client\KolibriClient;
use KuboKolibri\Models\CurriculumMap;

class CheckContentCommand extends Command
{
    protected $signature = 'kolibri:check-content
        {--detail : List every missing node id}';

    protected $description = 'Verify every curriculum_maps row points at content that exists in the local Kolibri install.';

    public function handle(KolibriClient $client): int
    {
        $maps = CurriculumMap::all();
        if ($maps->isEmpty()) {
            $this->info('No curriculum maps to check.');
            return self::SUCCESS;
        }

        $channelGroups = $maps->groupBy('kolibri_channel_id');
        $this->info("Checking {$maps->count()} mappings across {$channelGroups->count()} channel(s)...");

        $missingChannels = [];
        $missingNodes = [];

        foreach ($channelGroups as $channelId => $rows) {
            if (!$client->getChannel($channelId)) {
                $missingChannels[$channelId] = $rows;
                continue;
            }

            $nodeIds = $rows->pluck('kolibri_node_id')->unique()->values();
            $found = collect();
            foreach ($nodeIds->chunk(50) as $chunk) {
                $resp = $client->getContentNodes(['ids' => $chunk->implode(',')]);
                $found = $found->merge($resp->pluck('id'));
            }
            foreach ($nodeIds->diff($found) as $nodeId) {
                $missingNodes[$nodeId] = $rows->where('kolibri_node_id', $nodeId);
            }
        }

        $affected = collect($missingChannels)->sum(fn ($r) => $r->count())
            + collect($missingNodes)->sum(fn ($r) => $r->count());

        if (!$missingChannels && !$missingNodes) {
            $this->info("All {$maps->count()} mappings resolve to live Kolibri content.");
            return self::SUCCESS;
        }

        $this->newLine();
        if ($missingChannels) {
            $this->warn('Missing channels (not imported into local Kolibri):');
            foreach ($missingChannels as $cid => $rows) {
                $this->line("  {$cid}  — {$rows->count()} mapping(s) affected");
            }
        }

        if ($missingNodes) {
            $this->warn(count($missingNodes) . ' node(s) not found within imported channels:');
            if ($this->option('detail')) {
                foreach ($missingNodes as $nid => $rows) {
                    $first = $rows->first();
                    $this->line("  {$nid}  channel={$first->kolibri_channel_id}  kind={$first->content_kind}");
                }
            } else {
                $this->line('  (run with --detail to list)');
            }
        }

        $okCount = $maps->count() - $affected;
        $this->newLine();
        $this->info("  ok:       {$okCount}");
        $this->error("  missing:  {$affected}");

        return self::FAILURE;
    }
}
