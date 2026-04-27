<?php

namespace KuboKolibri\Services;

use KuboKolibri\Client\KolibriClient;

/**
 * Reads Perseus exercise data from Kolibri and converts it back
 * to KUBO's simple question format for cloning/editing.
 */
class PerseusReader
{
    public function __construct(private KolibriClient $client) {}

    /**
     * Extract questions from a Kolibri exercise node's assessment items.
     * Returns array of questions in KUBO's format, or empty array on failure.
     */
    public function readQuestions(string $nodeId): array
    {
        try {
            $node = $this->client->getContentNode($nodeId);
            if (!$node) {
                return [];
            }

            // Kolibri exposes assessment items via the assessment metadata
            $contentId = $node['content_id'] ?? null;
            if (!$contentId) {
                return [];
            }

            // Try to get assessment items from Kolibri's API
            $assessmentItems = $this->fetchAssessmentItems($nodeId);
            if (empty($assessmentItems)) {
                return [];
            }

            return $this->parseItems($assessmentItems);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Fetch assessment item data from Kolibri.
     * Kolibri serves Perseus JSON at /api/content/contentnode/{id}/assessment_items/
     */
    private function fetchAssessmentItems(string $nodeId): array
    {
        try {
            $results = $this->client->get("/api/content/contentnode/{$nodeId}/");
            $node = $results->first();

            if (!$node || empty($node['assessmentmetadata'])) {
                return [];
            }

            $metadata = $node['assessmentmetadata'];
            $itemIds = json_decode($metadata['assessment_item_ids'] ?? '[]', true);

            if (empty($itemIds)) {
                return [];
            }

            // Kolibri exposes individual items at the node level
            // The items contain Perseus JSON with question data
            return $this->client->get("/api/content/contentnode/{$nodeId}/", [
                'include_assessment_items' => true,
            ])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Parse Perseus item data into KUBO's question format.
     */
    private function parseItems(array $items): array
    {
        $questions = [];

        foreach ($items as $item) {
            if (!isset($item['item_data'])) {
                continue;
            }

            $data = is_string($item['item_data']) ? json_decode($item['item_data'], true) : $item['item_data'];
            if (!$data || !isset($data['question'])) {
                continue;
            }

            $question = $this->parsePerseusQuestion($data);
            if ($question) {
                $questions[] = $question;
            }
        }

        return $questions;
    }

    /**
     * Convert a single Perseus question JSON into KUBO's format.
     */
    private function parsePerseusQuestion(array $data): ?array
    {
        $questionData = $data['question'] ?? [];
        $content = $questionData['content'] ?? '';
        $widgets = $questionData['widgets'] ?? [];

        // Strip Perseus widget markers from question text
        $cleanText = preg_replace('/\[\[☃\s+[^\]]+\]\]/', '', $content);
        $cleanText = trim($cleanText);

        if (empty($cleanText)) {
            return null;
        }

        // Detect widget type
        foreach ($widgets as $widgetKey => $widget) {
            $type = $widget['type'] ?? '';

            if ($type === 'radio') {
                return $this->parseRadioWidget($cleanText, $widget, $data);
            }

            if ($type === 'numeric-input') {
                return $this->parseNumericWidget($cleanText, $widget, $data);
            }
        }

        // Unknown widget type — return as text-only
        return [
            'question_text' => $cleanText,
            'question_type' => 'radio',
            'choices' => [],
        ];
    }

    private function parseRadioWidget(string $text, array $widget, array $data): array
    {
        $options = $widget['options'] ?? [];
        $choices = [];

        foreach ($options['choices'] ?? [] as $choice) {
            $choices[] = [
                'text' => $choice['content'] ?? '',
                'correct' => (bool) ($choice['correct'] ?? false),
            ];
        }

        $question = [
            'question_text' => $text,
            'question_type' => 'radio',
            'choices' => $choices,
        ];

        // Extract hints
        $hints = $this->parseHints($data);
        if (!empty($hints)) {
            $question['hints'] = $hints;
        }

        return $question;
    }

    private function parseNumericWidget(string $text, array $widget, array $data): array
    {
        $options = $widget['options'] ?? [];
        $answers = $options['answers'] ?? [];
        $correctAnswer = '';

        foreach ($answers as $answer) {
            if (($answer['status'] ?? '') === 'correct') {
                $correctAnswer = (string) ($answer['value'] ?? '');
                break;
            }
        }

        $question = [
            'question_text' => $text,
            'question_type' => 'numeric_input',
            'correct_answer' => $correctAnswer,
        ];

        $hints = $this->parseHints($data);
        if (!empty($hints)) {
            $question['hints'] = $hints;
        }

        return $question;
    }

    private function parseHints(array $data): array
    {
        $hints = [];
        foreach ($data['hints'] ?? [] as $hint) {
            $content = $hint['content'] ?? '';
            if (!empty(trim($content))) {
                $hints[] = ['content' => $content];
            }
        }
        return $hints;
    }
}
