<?php

namespace KuboKolibri\Services;

use KuboKolibri\Client\KolibriClient;
use KuboKolibri\Models\ContentProgress;
use KuboKolibri\Models\CurriculumMap;

class ProgressSync
{
    private KolibriClient $client;

    public function __construct(KolibriClient $client)
    {
        $this->client = $client;
    }

    /**
     * Sync progress from Kolibri for a specific student.
     * Pulls summary logs and updates the content_progress table.
     */
    public function syncForStudent(int $userId, string $kolibriUserId): int
    {
        $maps = CurriculumMap::all();
        $kolibriNodeIds = $maps->pluck('kolibri_node_id')->unique()->toArray();

        if (empty($kolibriNodeIds)) {
            return 0;
        }

        $logs = $this->client->getProgressForUser($kolibriUserId, $kolibriNodeIds);
        $synced = 0;

        foreach ($logs as $log) {
            $contentId = $log['content_id'] ?? null;
            if (!$contentId) {
                continue;
            }

            $matchingMaps = $maps->where('kolibri_node_id', $contentId);

            foreach ($matchingMaps as $map) {
                ContentProgress::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'curriculum_map_id' => $map->id,
                    ],
                    [
                        'kolibri_log_id' => $log['id'] ?? null,
                        'score' => $this->normalizeScore($log),
                        'completed' => (bool) ($log['complete'] ?? false),
                        'time_spent' => (int) ($log['time_spent'] ?? 0),
                        'synced_at' => now(),
                    ]
                );
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Sync progress for all students in a class (offering).
     */
    public function syncForOffering(int $offeringId, callable $kolibriUserIdResolver): int
    {
        $enrollments = \App\Models\Enrollment::where('offering_id', $offeringId)
            ->with('student')
            ->get();

        $total = 0;
        foreach ($enrollments as $enrollment) {
            $kolibriUserId = $kolibriUserIdResolver($enrollment->user_id);
            if ($kolibriUserId) {
                $total += $this->syncForStudent($enrollment->user_id, $kolibriUserId);
            }
        }

        return $total;
    }

    private function normalizeScore(array $log): ?float
    {
        $progress = $log['progress'] ?? null;
        if ($progress === null) {
            return null;
        }

        // Kolibri stores progress as 0.0-1.0, we store as 0-100
        return round($progress * 100, 2);
    }
}
