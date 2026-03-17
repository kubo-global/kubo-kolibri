<?php

namespace KuboKolibri\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use KuboKolibri\Client\KolibriClient;
use KuboKolibri\Models\CurriculumMap;
use KuboKolibri\Services\AdaptiveEngine;
use KuboKolibri\Services\ContentResolver;
use KuboKolibri\Services\ProgressSync;

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
     * Embed a single Kolibri content node in an iframe.
     */
    public function embed(KolibriClient $client, string $nodeId)
    {
        $renderUrl = $client->renderUrl($nodeId);

        return view('kubo-kolibri::embed', [
            'renderUrl' => $renderUrl,
            'nodeId' => $nodeId,
        ]);
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
}
