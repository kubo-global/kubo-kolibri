<?php

namespace KuboKolibri\Console;

use App\Models\User;
use Illuminate\Console\Command;
use KuboKolibri\Services\ProgressSync;
use KuboKolibri\Services\SkillGraph;

class SyncProgressCommand extends Command
{
    protected $signature = 'kolibri:sync
        {--user= : Sync a specific KUBO user ID only}
        {--offering= : Sync all students in an offering}';

    protected $description = 'Pull student progress from Kolibri and update skill mastery';

    public function handle(ProgressSync $sync, SkillGraph $graph): int
    {
        $userId = $this->option('user');
        $offeringId = $this->option('offering');

        if ($userId) {
            return $this->syncUser((int) $userId, $sync, $graph);
        }

        if ($offeringId) {
            return $this->syncOffering((int) $offeringId, $sync, $graph);
        }

        return $this->syncAll($sync, $graph);
    }

    private function syncUser(int $userId, ProgressSync $sync, SkillGraph $graph): int
    {
        $user = User::find($userId);
        if (!$user || !$user->kolibri_user_id) {
            $this->error("User {$userId} not found or not provisioned in Kolibri.");
            return 1;
        }

        $count = $sync->syncForStudent($userId, $user->kolibri_user_id);
        $this->updateSkillMastery($userId, $graph);
        $this->info("Synced {$count} progress records for {$user->full_name}.");

        return 0;
    }

    private function syncOffering(int $offeringId, ProgressSync $sync, SkillGraph $graph): int
    {
        $count = $sync->syncForOffering($offeringId, function (int $userId) {
            return User::find($userId)?->kolibri_user_id;
        });

        $enrollments = \App\Models\Enrollment::where('offering_id', $offeringId)->get();
        foreach ($enrollments as $enrollment) {
            $this->updateSkillMastery($enrollment->user_id, $graph);
        }

        $this->info("Synced {$count} progress records for offering {$offeringId}.");
        return 0;
    }

    private function syncAll(ProgressSync $sync, SkillGraph $graph): int
    {
        $users = User::whereNotNull('kolibri_user_id')->get();

        if ($users->isEmpty()) {
            $this->info('No provisioned Kolibri users found.');
            return 0;
        }

        $total = 0;
        $bar = $this->output->createProgressBar($users->count());

        foreach ($users as $user) {
            $total += $sync->syncForStudent($user->id, $user->kolibri_user_id);
            $this->updateSkillMastery($user->id, $graph);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Synced {$total} progress records for {$users->count()} students.");

        return 0;
    }

    /**
     * After syncing progress, update skill mastery from content_progress.
     */
    private function updateSkillMastery(int $userId, SkillGraph $graph): void
    {
        $progressRecords = \KuboKolibri\Models\ContentProgress::where('user_id', $userId)
            ->where('completed', true)
            ->with('curriculumMap')
            ->get();

        foreach ($progressRecords as $progress) {
            $map = $progress->curriculumMap;
            if (!$map) {
                continue;
            }

            // Find skills linked to this content
            $skills = \KuboKolibri\Models\Skill::whereHas('content', function ($q) use ($map) {
                $q->where('curriculum_map_id', $map->id);
            })->get();

            foreach ($skills as $skill) {
                $graph->recordAttempt($userId, $skill, $progress->score ?? 100);
            }
        }
    }
}
