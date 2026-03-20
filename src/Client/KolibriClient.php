<?php

namespace KuboKolibri\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;

class KolibriClient
{
    private Client $http;
    private string $baseUrl;
    private ?string $csrfToken = null;

    public function __construct(string $baseUrl, string $username, string $password)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 10,
            'cookies' => true,
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
            $this->http->post('/api/auth/session/', [
                'json' => [
                    'username' => $username,
                    'password' => $password,
                    'facility' => $this->getDefaultFacilityId(),
                ],
            ]);

            // Extract CSRF token from cookies (Kolibri uses kolibri_csrftoken)
            $cookieJar = $this->http->getConfig('cookies');
            foreach ($cookieJar as $cookie) {
                if (str_contains($cookie->getName(), 'csrf')) {
                    $this->csrfToken = $cookie->getValue();
                    break;
                }
            }
        } catch (GuzzleException $e) {
            // Auth failed — client will work without auth for public endpoints
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

    public function deleteLesson(string $lessonId): void
    {
        $this->delete("/api/lessons/lesson/{$lessonId}/");
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

    /**
     * Fetch exercise scores from Kolibri's attempt logs for a specific user/content,
     * filtered to attempts since a given timestamp.
     *
     * @return array{total_questions: int, correct_answers: int, wrong_answers: int, score: float}
     */
    public function fetchExerciseScore(string $userId, string $contentId, \DateTimeInterface $since): array
    {
        $attempts = $this->getAttemptLogs($userId, $contentId);

        $sinceUnix = $since->getTimestamp();

        $filtered = $attempts->filter(function ($attempt) use ($sinceUnix) {
            $ts = $attempt['start_timestamp'] ?? '';
            if (!$ts) return false;
            try {
                return (new \DateTimeImmutable($ts))->getTimestamp() >= $sinceUnix;
            } catch (\Exception $e) {
                return false;
            }
        });

        $total = $filtered->count();
        $correct = $filtered->filter(fn ($a) => ($a['correct'] ?? 0) == 1)->count();
        $wrong = $total - $correct;

        return [
            'total_questions' => $total,
            'correct_answers' => $correct,
            'wrong_answers' => $wrong,
            'score' => $total > 0 ? round(($correct / $total) * 100, 2) : 0,
        ];
    }

    public function getAllAttemptLogs(string $contentId): Collection
    {
        return $this->get('/api/logger/attemptlog/', [
            'content' => $contentId,
        ]);
    }

    public function getContentSessionLogs(string $userId, string $contentId): Collection
    {
        return $this->get('/api/logger/contentsessionlog/', [
            'user_id' => $userId,
            'content_id' => $contentId,
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

    public function proxyRenderUrl(string $nodeId): string
    {
        return "/kolibri-proxy/learn/#/topics/c/{$nodeId}";
    }

    public function sessionApiUrl(): string
    {
        return "{$this->baseUrl}/api/auth/session/";
    }

    public function proxySessionApiUrl(): string
    {
        return "/kolibri-proxy/api/auth/session/";
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
            if ($this->csrfToken) {
                $options['headers']['X-CSRFToken'] = $this->csrfToken;
            }

            $response = $this->http->post($path, $options);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            \Illuminate\Support\Facades\Log::warning('Kolibri POST failed', [
                'path' => $path,
                'status' => method_exists($e, 'getResponse') && $e->getResponse() ? $e->getResponse()->getStatusCode() : null,
                'body' => method_exists($e, 'getResponse') && $e->getResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage(),
            ]);
            return null;
        }
    }

    private function delete(string $path): void
    {
        try {
            $options = [];
            if ($this->csrfToken) {
                $options['headers']['X-CSRFToken'] = $this->csrfToken;
            }

            $this->http->delete($path, $options);
        } catch (GuzzleException $e) {
            // Ignore — lesson may already be deleted
        }
    }
}
