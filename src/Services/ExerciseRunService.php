<?php

namespace KuboKolibri\Services;

use App\Models\User;
use App\Support\SchoolCalendar;
use KuboKolibri\Client\KolibriClient;
use App\Domain\Learning\Models\ExerciseRun;
use App\Domain\Learning\Models\LessonAssignment;
use App\Domain\Learning\Models\Skill;

class ExerciseRunService
{
    public function __construct(
        private KolibriClient $client,
        private SkillGraph $graph,
    ) {}

    /**
     * Start a new exercise run, abandoning any existing active run.
     * Validates homework mode against actual assignments, downgrading to free if bogus.
     */
    public function startRun(User $user, Skill $skill, string $mode = 'free', ?int $offeringId = null, ?int $currentWeek = null): ExerciseRun
    {
        $exerciseMap = $skill->practiceContent()->first();

        if (!$exerciseMap) {
            throw new \RuntimeException('No exercise available for this skill.');
        }

        $currentWeek ??= SchoolCalendar::currentSchoolWeek();
        $assignmentId = null;

        if ($mode === 'homework') {
            $assignment = $offeringId
                ? LessonAssignment::forOffering($offeringId)
                    ->forWeek($currentWeek)
                    ->where('skill_id', $skill->id)
                    ->first()
                : null;

            if ($assignment) {
                $assignmentId = $assignment->id;
            } else {
                $mode = 'free';
            }
        }

        ExerciseRun::forStudent($user->id)
            ->forSkill($skill->id)
            ->active()
            ->update(['status' => 'abandoned']);

        return ExerciseRun::create([
            'user_id' => $user->id,
            'skill_id' => $skill->id,
            'curriculum_map_id' => $exerciseMap->id,
            'status' => 'active',
            'mode' => $mode,
            'lesson_assignment_id' => $assignmentId,
            'started_at' => now(),
        ]);
    }

    /**
     * Complete an active run: fetch scores from Kolibri, mark completed,
     * and record the mastery attempt if questions were answered.
     *
     * If Kolibri is unreachable, the run is marked 'score_pending' so
     * it can be retried later rather than silently losing student work.
     */
    public function completeRun(ExerciseRun $run, User $user, Skill $skill): ExerciseRun
    {
        $kolibriError = $this->populateScores($run, $user);

        if ($run->total_questions > 0) {
            $run->status = 'completed';
            $run->completed_at = now();
            $run->save();

            $this->graph->recordAttempt($user->id, $skill, $run->score, SchoolCalendar::currentSchoolWeek());
        } elseif ($kolibriError) {
            // Kolibri was unreachable — don't abandon, keep for retry
            $run->status = 'score_pending';
            $run->completed_at = now();
            $run->save();
        } else {
            $run->status = 'abandoned';
            $run->save();
        }

        return $run;
    }

    /**
     * Retry score fetching for a pending run.
     */
    public function retryScores(ExerciseRun $run, User $user, Skill $skill): ExerciseRun
    {
        $this->populateScores($run, $user);

        if ($run->total_questions > 0) {
            $run->status = 'completed';
            $run->save();

            $this->graph->recordAttempt($user->id, $skill, $run->score, SchoolCalendar::currentSchoolWeek());
        }

        return $run;
    }

    /**
     * Fetch exercise scores from Kolibri's attempt logs and populate the run.
     * Returns true if there was a Kolibri connectivity error, false otherwise.
     */
    public function populateScores(ExerciseRun $run, User $user): bool
    {
        if ($run->total_questions > 0) {
            return false;
        }

        if (!$user->kolibri_user_id) {
            return false;
        }

        $map = $run->curriculumMap;
        if (!$map) {
            return false;
        }

        try {
            $contentId = $map->resolveContentId($this->client);
            if (!$contentId) {
                return false;
            }

            $scores = $this->client->fetchExerciseScore(
                $user->kolibri_user_id,
                $contentId,
                $run->started_at,
            );

            if ($scores['total_questions'] > 0) {
                $run->total_questions = $scores['total_questions'];
                $run->correct_answers = $scores['correct_answers'];
                $run->wrong_answers = $scores['wrong_answers'];
                $run->score = $scores['score'];
            } else {
                // Fallback: check contentsummarylog for completion data
                $progress = $this->client->getProgressForUser($user->kolibri_user_id, [$contentId]);
                $summary = $progress->first();
                if ($summary && ($summary['progress'] ?? 0) > 0) {
                    $run->total_questions = 1;
                    $run->correct_answers = $summary['progress'] >= 1.0 ? 1 : 0;
                    $run->wrong_answers = $summary['progress'] >= 1.0 ? 0 : 1;
                    $run->score = round(($summary['progress'] ?? 0) * 100, 2);
                }
            }

            return false;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Kolibri score fetch failed', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Find the next skill in sequence after the given skill,
     * respecting scheduled_week pacing and optionally requiring practice content.
     */
    public function nextSkillInSequence(Skill $skill, ?int $currentWeek = null, bool $requirePracticeContent = true): ?Skill
    {
        $currentWeek ??= SchoolCalendar::currentSchoolWeek();

        $query = Skill::where('subject_id', $skill->subject_id)
            ->where('grade_id', $skill->grade_id)
            ->where('level', '>', $skill->level)
            ->where(function ($q) use ($currentWeek) {
                $q->whereNull('scheduled_week')
                  ->orWhere('scheduled_week', '<=', $currentWeek);
            })
            ->orderBy('level');

        if ($requirePracticeContent) {
            $query->whereHas('content', fn ($q) => $q->where('role', 'practice'));
        }

        return $query->first();
    }
}
