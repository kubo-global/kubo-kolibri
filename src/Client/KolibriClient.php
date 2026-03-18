<?php

namespace KuboKolibri\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;

class KolibriClient
{
    private Client $http;
    private string $baseUrl;
    private ?string $token = null;

    public function __construct(string $baseUrl, string $username, string $password)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 10,
        ]);

        if ($username && $password) {
            $this->authenticate($username, $password);
        }
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    private function authenticate(string $username, string $password): void
    {
        try {
            $response = $this->http->post('/api/auth/session/', [
                'json' => [
                    'username' => $username,
                    'password' => $password,
                    'facility' => $this->getDefaultFacilityId(),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $this->token = $data['token'] ?? null;
        } catch (GuzzleException $e) {
            // Auth failed — client will work without token for public endpoints
        }
    }

    private function getDefaultFacilityId(): ?string
    {
        try {
            $response = $this->http->get('/api/auth/facility/');
            $facilities = json_decode($response->getBody()->getContents(), true);
            return $facilities[0]['id'] ?? null;
        } catch (GuzzleException $e) {
            return null;
        }
    }

    // =========================================================================
    // Channels
    // =========================================================================

    public function getChannels(): Collection
    {
        return $this->get('/api/content/channel/');
    }

    public function getChannel(string $channelId): ?array
    {
        return $this->get("/api/content/channel/{$channelId}/")->first();
    }

    // =========================================================================
    // Content Nodes
    // =========================================================================

    public function getContentNode(string $nodeId): ?array
    {
        $results = $this->get("/api/content/contentnode/{$nodeId}/");
        return $results->isNotEmpty() ? $results->first() : null;
    }

    public function getContentNodes(array $filters = []): Collection
    {
        return $this->get('/api/content/contentnode/', $filters);
    }

    public function getChildNodes(string $parentId): Collection
    {
        return $this->getContentNodes(['parent' => $parentId]);
    }

    public function getContentTree(string $nodeId, int $depth = 2): ?array
    {
        $results = $this->get("/api/content/contentnode_tree/{$nodeId}/", [
            'depth' => $depth,
        ]);
        return $results->isNotEmpty() ? $results->first() : null;
    }

    public function searchContent(string $query, array $filters = []): Collection
    {
        return $this->get('/api/content/contentnode_search/', array_merge(
            ['search' => $query],
            $filters
        ));
    }

    public function getExercisesForTopic(string $topicNodeId): Collection
    {
        return $this->getContentNodes([
            'parent' => $topicNodeId,
            'kind' => 'exercise',
        ]);
    }

    public function getVideosForTopic(string $topicNodeId): Collection
    {
        return $this->getContentNodes([
            'parent' => $topicNodeId,
            'kind' => 'video',
        ]);
    }

    // =========================================================================
    // Provisioning
    // =========================================================================

    public function createFacility(string $name): ?array
    {
        return $this->post('/api/auth/facility/', [
            'name' => $name,
        ]);
    }

    public function createClassroom(string $facilityId, string $name): ?array
    {
        return $this->post('/api/auth/classroom/', [
            'name' => $name,
            'parent' => $facilityId,
        ]);
    }

    public function createLearner(string $facilityId, string $username, string $fullName, string $password): ?array
    {
        return $this->post('/api/auth/facilityuser/', [
            'username' => $username,
            'full_name' => $fullName,
            'password' => $password,
            'facility' => $facilityId,
        ]);
    }

    public function addToClassroom(string $userId, string $classroomId): ?array
    {
        return $this->post('/api/auth/membership/', [
            'user' => $userId,
            'collection' => $classroomId,
        ]);
    }

    public function createLesson(string $classroomId, string $title, array $resources, string $createdBy): ?array
    {
        return $this->post('/api/lessons/lesson/', [
            'title' => $title,
            'collection' => $classroomId,
            'resources' => $resources,
            'created_by' => $createdBy,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // Progress & Attempt Logs
    // =========================================================================

    public function getProgressForUser(string $userId, array $contentIds = []): Collection
    {
        $params = [];
        if (!empty($contentIds)) {
            $params['content_id_in'] = implode(',', $contentIds);
        }

        return $this->get("/api/logger/contentsummarylog/", array_merge(
            ['user_id' => $userId],
            $params,
        ));
    }

    public function getAttemptLogs(string $userId, string $contentId): Collection
    {
        return $this->get('/api/logger/attemptlog/', [
            'user' => $userId,
            'content' => $contentId,
        ]);
    }

    public function getMasteryLogs(string $userId, string $contentId): Collection
    {
        return $this->get('/api/logger/masterylog/', [
            'user' => $userId,
            'summarylog__content_id' => $contentId,
        ]);
    }

    // =========================================================================
    // URLs
    // =========================================================================

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function renderUrl(string $nodeId): string
    {
        return "{$this->baseUrl}/learn/#/topics/c/{$nodeId}";
    }

    public function sessionApiUrl(): string
    {
        return "{$this->baseUrl}/api/auth/session/";
    }

    // =========================================================================
    // HTTP
    // =========================================================================

    private function get(string $path, array $params = []): Collection
    {
        try {
            $options = [];
            if (!empty($params)) {
                $options['query'] = $params;
            }
            if ($this->token) {
                $options['headers']['Authorization'] = "Token {$this->token}";
            }

            $response = $this->http->get($path, $options);
            $data = json_decode($response->getBody()->getContents(), true);

            // Handle paginated responses
            if (isset($data['results'])) {
                return collect($data['results']);
            }

            // Single object response
            if (isset($data['id'])) {
                return collect([$data]);
            }

            return collect($data);
        } catch (GuzzleException $e) {
            return collect();
        }
    }

    private function post(string $path, array $data = []): ?array
    {
        try {
            $options = ['json' => $data];
            if ($this->token) {
                $options['headers']['Authorization'] = "Token {$this->token}";
            }

            $response = $this->http->post($path, $options);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return null;
        }
    }
}
