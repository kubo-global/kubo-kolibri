<?php

namespace KuboKolibri\Services;

use Illuminate\Support\Collection;
use KuboKolibri\Client\KolibriClient;
use KuboKolibri\Models\ContentProgress;
use KuboKolibri\Models\CurriculumMap;

/**
 * Selects the right exercise for a student based on their current level.
 *
 * Implements the binary search approach from the 2017 prototype:
 * serve the middle exercise of a difficulty range, then narrow
 * based on whether the student answered correctly.
 */
class AdaptiveEngine
{
    private KolibriClient $client;

    public function __construct(KolibriClient $client)
    {
        $this->client = $client;
    }

    /**
     * Get the next exercise for a student on a given topic.
     *
     * Strategy:
     * 1. Get all exercises mapped to this topic, ordered by display_order (difficulty)
     * 2. Check which ones the student has completed
     * 3. Find the boundary — last completed exercise
     * 4. Serve the next uncompleted one, or the middle of the remaining range
     */
    public function nextExercise(int $userId, int $subjectId, int $topicId): ?array
    {
        $exercises = CurriculumMap::forSubject($subjectId)
            ->forTopic($topicId)
            ->exercises()
            ->orderBy('display_order')
            ->get();

        if ($exercises->isEmpty()) {
            return null;
        }

        $completedIds = ContentProgress::forStudent($userId)
            ->completed()
            ->whereIn('curriculum_map_id', $exercises->pluck('id'))
            ->pluck('curriculum_map_id')
            ->toArray();

        $remaining = $exercises->reject(fn ($ex) => in_array($ex->id, $completedIds));

        if ($remaining->isEmpty()) {
            // All completed — serve the hardest one for review
            $target = $exercises->last();
        } else {
            // Serve the first uncompleted exercise (progressive difficulty)
            $target = $remaining->first();
        }

        $node = $this->client->getContentNode($target->kolibri_node_id);
        if (!$node) {
            return null;
        }

        return [
            'map' => $target,
            'node' => $node,
            'render_url' => $this->client->renderUrl($target->kolibri_node_id),
            'position' => $exercises->search(fn ($ex) => $ex->id === $target->id) + 1,
            'total' => $exercises->count(),
            'completed' => count($completedIds),
        ];
    }

    /**
     * Binary search for skill level assessment.
     *
     * Given a range of exercises ordered by difficulty, serve the middle one.
     * Based on the result, narrow to the harder or easier half.
     * Returns the exercise to serve and the narrowed range boundaries.
     */
    public function assessLevel(
        int $userId,
        int $subjectId,
        int $topicId,
        int $lowerBound = 0,
        ?int $upperBound = null,
    ): ?array {
        $exercises = CurriculumMap::forSubject($subjectId)
            ->forTopic($topicId)
            ->exercises()
            ->orderBy('display_order')
            ->get();

        if ($exercises->isEmpty()) {
            return null;
        }

        if ($upperBound === null) {
            $upperBound = $exercises->count() - 1;
        }

        if ($lowerBound > $upperBound) {
            return ['converged' => true, 'level' => $lowerBound, 'total' => $exercises->count()];
        }

        $mid = (int) floor(($lowerBound + $upperBound) / 2);
        $target = $exercises->get($mid);

        if (!$target) {
            return null;
        }

        $node = $this->client->getContentNode($target->kolibri_node_id);

        return [
            'converged' => false,
            'map' => $target,
            'node' => $node,
            'render_url' => $this->client->renderUrl($target->kolibri_node_id),
            'lower_bound' => $lowerBound,
            'upper_bound' => $upperBound,
            'position' => $mid,
            'total' => $exercises->count(),
        ];
    }
}
