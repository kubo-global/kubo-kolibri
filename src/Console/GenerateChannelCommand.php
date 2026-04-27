<?php

namespace KuboKolibri\Console;

use Illuminate\Console\Command;
use KuboKolibri\Services\ChannelGenerator;

class GenerateChannelCommand extends Command
{
    protected $signature = 'kubo:generate-channel
        {--school= : School ID (defaults to first school)}';

    protected $description = 'Generate a Kolibri channel from teacher-authored exercises';

    public function handle(ChannelGenerator $generator): int
    {
        $schoolId = $this->option('school')
            ?? \App\Models\School::first()?->id;

        if (!$schoolId) {
            $this->error('No school found.');
            return 1;
        }

        $this->info("Generating channel for school {$schoolId}...");

        $result = $generator->generate((int) $schoolId);

        if (!$result['channel_id']) {
            $this->warn('No authored exercises found for this school.');
            return 0;
        }

        $this->info("Channel {$result['channel_id']} generated with {$result['exercises']} exercise(s).");
        $this->info("Database: {$result['db_path']}");

        return 0;
    }
}
