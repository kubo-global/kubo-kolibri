<?php

namespace KuboKolibri\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use KuboKolibri\Client\KolibriClient;
use KuboKolibri\Models\CurriculumMap;
use KuboKolibri\Services\AdaptiveEngine;
use KuboKolibri\Services\ContentResolver;
use KuboKolibri\Services\KolibriSessionBridge;

class ContentController extends Controller
{
    /**
     * Show mapped content for a subject/topic — the student view.
     */
    public function forTopic(Request $request, ContentResolver $resolver, int $subjectId, int $topicId)
    {
        $content = $resolver->forSubject($subjectId, $topicId);

        return view('kubo-kolibri::topic-content', [
            'exercises' => $content->filter(fn ($c) => $c['map']->content_kind === 'exercise'),
            'videos' => $content->filter(fn ($c) => $c['map']->content_kind === 'video'),
            'subjectId' => $subjectId,
            'topicId' => $topicId,
        ]);
    }

    /**
     * Redirect a student to Kolibri content via the session bridge.
     * Auto-logs the student into Kolibri, then redirects to the content.
     */
    public function redirect(Request $request, KolibriSessionBridge $bridge, KolibriClient $client, string $nodeId)
    {
        $user = $request->user();
        $facilityId = $this->getFacilityId($user);

        // If school is provisioned, try auto-login via session bridge
        if ($facilityId && $user->kolibri_user_id) {
            $data = $bridge->buildRedirectData($user, $facilityId, $nodeId);
            return view('kubo-kolibri::kolibri-redirect', $data);
        }

        // Not provisioned — redirect straight to Kolibri content URL
        return redirect()->away($client->renderUrl($nodeId));
    }

    /**
     * Get the next adaptive exercise for the logged-in student.
     */
    public function nextExercise(Request $request, AdaptiveEngine $engine, int $subjectId, int $topicId)
    {
        $userId = $request->user()->id;
        $exercise = $engine->nextExercise($userId, $subjectId, $topicId);

        if (!$exercise) {
            return response()->json(['message' => 'No exercises available for this topic'], 404);
        }

        return response()->json($exercise);
    }

    /**
     * Browse Kolibri channels — for curriculum mapping UI.
     */
    public function channels(KolibriClient $client)
    {
        return response()->json($client->getChannels());
    }

    /**
     * Browse content tree — for curriculum mapping UI.
     */
    public function browseContent(KolibriClient $client, string $nodeId)
    {
        $tree = $client->getContentTree($nodeId);

        return response()->json($tree);
    }

    /**
     * Search Kolibri content — for curriculum mapping UI.
     */
    public function searchContent(Request $request, ContentResolver $resolver)
    {
        $request->validate(['q' => 'required|string|min:2']);

        $results = $resolver->searchForMapping(
            $request->input('q'),
            $request->input('channel_id'),
        );

        return response()->json($results);
    }

    /**
     * Create a curriculum mapping.
     */
    public function createMapping(Request $request)
    {
        $validated = $request->validate([
            'school_id' => 'required|exists:schools,id',
            'subject_id' => 'required|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'kolibri_channel_id' => 'required|uuid',
            'kolibri_node_id' => 'required|uuid',
            'content_kind' => 'required|in:exercise,video,html5,document,audio',
            'display_order' => 'integer|min:0',
        ]);

        $validated['mapped_by'] = $request->user()->id;

        $map = CurriculumMap::create($validated);

        return response()->json($map, 201);
    }

    /**
     * Delete a curriculum mapping.
     */
    public function deleteMapping(int $mapId)
    {
        CurriculumMap::findOrFail($mapId)->delete();

        return response()->json(null, 204);
    }

    private function getFacilityId($user): ?string
    {
        // Walk from user → enrollment → offering → school to find facility ID
        $enrollment = $user->enrollments()->with('offering')->latest()->first();
        if (!$enrollment || !$enrollment->offering) {
            return null;
        }

        // Offering doesn't directly have school_id, but grade → school relationship
        // For now, use the school from the config or the first school
        $school = \App\Models\School::whereNotNull('kolibri_facility_id')->first();
        return $school?->kolibri_facility_id;
    }
}
