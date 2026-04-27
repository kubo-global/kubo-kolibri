<?php

namespace KuboKolibri\Services;

use App\Domain\Learning\Models\AuthoredExercise;
use App\Domain\Learning\Models\AuthoredQuestion;
use ZipArchive;

class PerseusGenerator
{
    /**
     * Generate a .perseus zip file for an exercise.
     *
     * Returns the raw zip content as a string (caller decides where to write it).
     */
    public function generate(AuthoredExercise $exercise): string
    {
        $exercise->loadMissing('questions');

        $itemIds = [];
        $itemFiles = [];

        foreach ($exercise->questions as $question) {
            $itemId = $question->getAssessmentItemId();
            $itemIds[] = $itemId;
            $itemFiles[$itemId] = $this->buildQuestionJson($question);
        }

        $exerciseJson = json_encode([
            'all_assessment_items' => array_map(fn ($id) => ['id' => $id], $itemIds),
            'current_version' => 2,
            'seed' => $exercise->id,
        ], JSON_UNESCAPED_UNICODE);

        $tmpFile = tempnam(sys_get_temp_dir(), 'perseus_');

        $zip = new ZipArchive();
        $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        // Use STORE compression (no deflation) — Kolibri expects this
        $zip->addFromString('exercise.json', $exerciseJson);
        $zip->setCompressionName('exercise.json', ZipArchive::CM_STORE);

        foreach ($itemFiles as $itemId => $json) {
            $filename = "{$itemId}.json";
            $zip->addFromString($filename, $json);
            $zip->setCompressionName($filename, ZipArchive::CM_STORE);
        }

        $zip->close();

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $content;
    }

    /**
     * Build the Perseus JSON for a single question.
     */
    private function buildQuestionJson(AuthoredQuestion $question): string
    {
        $hints = [];
        if ($question->hints) {
            foreach ($question->hints as $hint) {
                $hints[] = [
                    'content' => $hint['content'] ?? '',
                    'images' => new \stdClass(),
                    'widgets' => new \stdClass(),
                ];
            }
        }

        if ($question->question_type === 'numeric_input') {
            $questionData = $this->buildNumericInput($question, $hints);
        } else {
            $questionData = $this->buildRadio($question, $hints);
        }

        return json_encode($questionData, JSON_UNESCAPED_UNICODE);
    }

    private function buildRadio(AuthoredQuestion $question, array $hints): array
    {
        $choices = [];
        foreach ($question->choices ?? [] as $choice) {
            $choices[] = [
                'content' => $choice['text'] ?? '',
                'correct' => (bool) ($choice['correct'] ?? false),
            ];
        }

        return [
            'answerArea' => ['calculator' => false],
            'hints' => $hints,
            'itemDataVersion' => ['major' => 0, 'minor' => 1],
            'question' => [
                'content' => $question->question_text . "\n\n[[☃ radio 1]]",
                'images' => new \stdClass(),
                'widgets' => [
                    'radio 1' => [
                        'type' => 'radio',
                        'graded' => true,
                        'options' => [
                            'choices' => $choices,
                            'deselectEnabled' => false,
                            'multipleSelect' => false,
                            'onePerLine' => true,
                            'randomize' => false,
                        ],
                        'version' => ['major' => 0, 'minor' => 0],
                    ],
                ],
            ],
        ];
    }

    private function buildNumericInput(AuthoredQuestion $question, array $hints): array
    {
        return [
            'answerArea' => ['calculator' => false],
            'hints' => $hints,
            'itemDataVersion' => ['major' => 0, 'minor' => 1],
            'question' => [
                'content' => $question->question_text . "\n\n[[☃ numeric-input 1]]",
                'images' => new \stdClass(),
                'widgets' => [
                    'numeric-input 1' => [
                        'type' => 'numeric-input',
                        'graded' => true,
                        'options' => [
                            'answers' => [
                                [
                                    'value' => is_numeric($question->correct_answer) ? (float) $question->correct_answer : 0,
                                    'status' => 'correct',
                                    'message' => '',
                                    'simplify' => 'required',
                                    'strict' => false,
                                    'maxError' => null,
                                ],
                            ],
                            'size' => 'normal',
                            'coefficient' => false,
                            'labelText' => '',
                        ],
                        'version' => ['major' => 0, 'minor' => 0],
                    ],
                ],
            ],
        ];
    }
}
