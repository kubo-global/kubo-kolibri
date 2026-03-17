<?php

namespace KuboKolibri\Services;

use Illuminate\Support\Collection;
use KuboKolibri\Client\KolibriClient;
use KuboKolibri\Models\CurriculumMap;

class ContentResolver
{
    private KolibriClient $client;

    public function __construct(KolibriClient $client)
    {
        $this->client = $client;
    }

    /**
     * Get all mapped content for a subject, optionally filtered by topic.
     */
    public function forSubject(int $subjectId, ?int $topicId = null): Collection
    {
        $query = CurriculumMap::forSubject($subjectId)->orderBy('display_order');

        if ($topicId) {
            $query->forTopic($topicId);
        }

        return $query->get()->map(function (CurriculumMap $map) {
            return [
                'map' => $map,
                'node' => $this->client->getContentNode($map->kolibri_node_id),
                'render_url' => $this->client->renderUrl($map->kolibri_node_id),
            ];
        })->filter(fn ($item) => $item['node'] !== null);
    }

    /**
     * Get exercises only for a topic — what the student practices.
     */
    public function exercisesForTopic(int $subjectId, int $topicId): Collection
    {
        return $this->forSubject($subjectId, $topicId)
            ->filter(fn ($item) => $item['map']->content_kind === 'exercise');
    }

    /**
     * Get videos only for a topic — what the student watches.
     */
    public function videosForTopic(int $subjectId, int $topicId): Collection
    {
        return $this->forSubject($subjectId, $topicId)
            ->filter(fn ($item) => $item['map']->content_kind === 'video');
    }

    /**
     * Search Kolibri for content that could map to a subject/topic.
     * Used by teachers when building curriculum maps.
     */
    public function searchForMapping(string $query, ?string $channelId = null): Collection
    {
        $filters = [];
        if ($channelId) {
            $filters['channel_id'] = $channelId;
        }

        return $this->client->searchContent($query, $filters);
    }
}
