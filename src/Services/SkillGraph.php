<?php

namespace KuboKolibri\Services;

use Illuminate\Support\Collection;
use KuboKolibri\Models\Skill;
use KuboKolibri\Models\StudentSkill;

/**
 * Traverses the skill prerequisite graph to find where a student
 * actually is, regardless of where their grade says they should be.
 *
 * Grade 6 says: "calculate area of rectangles"
 * Student can't → check prerequisite: "multiplication"
 * Student can't → check prerequisite: "repeated addition"
 * Student CAN → start here, build up.
 */
class SkillGraph
{
    /**
     * Get the competence map for a student in a subject.
     * Returns all skills with their mastery status.
     */
    public function competenceMap(int $userId, int $subjectId): Collection
    {
        $skills = Skill::where('subject_id', $subjectId)
            ->orderBy('level')
            ->get();

        $studentSkills = StudentSkill::forStudent($userId)
            ->whereIn('skill_id', $skills->pluck('id'))
            ->get()
            ->keyBy('skill_id');

        return $skills->map(function (Skill $skill) use ($studentSkills) {
            $ss = $studentSkills->get($skill->id);
            return [
                'skill' => $skill,
                'status' => $ss ? $ss->status : 'not_started',
                'mastery' => $ss ? (float) $ss->mastery : 0,
                'attempts' => $ss ? $ss->attempts : 0,
            ];
        });
    }

    /**
     * Find the right starting skill for a student.
     *
     * Takes a target skill (what the grade curriculum says they should know)
     * and walks DOWN the prerequisite chain until it finds a skill
     * the student has mastered, then returns the next one up.
     */
    public function findStartingPoint(int $userId, Skill $targetSkill): Skill
    {
        // If student has mastered the target, they're done
        if ($this->hasMastered($userId, $targetSkill)) {
            return $targetSkill;
        }

        // Walk down prerequisites depth-first
        $prerequisites = $targetSkill->prerequisites()
            ->orderByDesc('level')
            ->get();

        foreach ($prerequisites as $prereq) {
            if ($this->hasMastered($userId, $prereq)) {
                // Student knows this prereq — the gap starts one level up
                continue;
            }

            // Student doesn't know this prereq either — go deeper
            return $this->findStartingPoint($userId, $prereq);
        }

        // No prerequisites mastered (or no prerequisites exist)
        // This skill IS the starting point
        return $targetSkill;
    }

    /**
     * Get the learning path from current level to target skill.
     * Returns skills in the order they should be learned.
     */
    public function learningPath(int $userId, Skill $targetSkill): Collection
    {
        $startingPoint = $this->findStartingPoint($userId, $targetSkill);

        // BFS from starting point up to target
        return $this->pathBetween($startingPoint, $targetSkill)
            ->reject(fn (Skill $skill) => $this->hasMastered($userId, $skill));
    }

    /**
     * Get all skills a student should work on right now.
     * These are skills whose prerequisites are all mastered
     * but the skill itself is not yet mastered.
     */
    public function readySkills(int $userId, int $subjectId, ?int $gradeId = null): Collection
    {
        $query = Skill::where('subject_id', $subjectId)
            ->with('prerequisites')
            ->orderBy('level');

        if ($gradeId) {
            $query->where('grade_id', $gradeId);
        }

        $skills = $query->get();

        $masteredIds = StudentSkill::forStudent($userId)
            ->mastered()
            ->pluck('skill_id')
            ->toArray();

        return $skills->filter(function (Skill $skill) use ($masteredIds) {
            // Skip already mastered
            if (in_array($skill->id, $masteredIds)) {
                return false;
            }

            // All prerequisites must be mastered
            if ($skill->prerequisites->isEmpty()) {
                return true;
            }

            return $skill->prerequisites->every(
                fn (Skill $prereq) => in_array($prereq->id, $masteredIds)
            );
        })->values();
    }

    /**
     * Record mastery of a skill based on exercise performance.
     */
    public function recordAttempt(int $userId, Skill $skill, float $score): StudentSkill
    {
        $studentSkill = StudentSkill::firstOrCreate(
            ['user_id' => $userId, 'skill_id' => $skill->id],
            ['status' => 'not_started', 'mastery' => 0, 'attempts' => 0]
        );

        $studentSkill->attempts++;
        $studentSkill->last_attempted_at = now();

        // Weighted moving average — recent attempts matter more
        // Converges quickly: 2 scores of 90+ → mastery
        $studentSkill->mastery = round(
            ($studentSkill->mastery * 0.3) + ($score * 0.7),
            2
        );

        if ($studentSkill->mastery >= 80 && $studentSkill->attempts >= 2) {
            $studentSkill->status = 'mastered';
            $studentSkill->mastered_at = now();
        } elseif ($studentSkill->attempts > 0) {
            $studentSkill->status = 'in_progress';
        }

        $studentSkill->save();

        return $studentSkill;
    }

    /**
     * Diagnose a student's level by finding the boundary in the skill graph.
     * Returns: skills they've mastered, the frontier (next to learn),
     * and skills that are too advanced.
     */
    public function diagnose(int $userId, int $subjectId, ?int $gradeId = null): array
    {
        $query = Skill::where('subject_id', $subjectId)->orderBy('level');

        if ($gradeId) {
            $query->where('grade_id', $gradeId);
        }

        $skills = $query->get();

        $masteredIds = StudentSkill::forStudent($userId)
            ->mastered()
            ->pluck('skill_id')
            ->toArray();

        $mastered = $skills->filter(fn ($s) => in_array($s->id, $masteredIds));
        $frontier = $this->readySkills($userId, $subjectId, $gradeId);
        $ahead = $skills->reject(fn ($s) => in_array($s->id, $masteredIds))
            ->reject(fn ($s) => $frontier->contains('id', $s->id));

        return [
            'mastered' => $mastered->values(),
            'frontier' => $frontier,
            'ahead' => $ahead->values(),
            'mastery_percentage' => $skills->count() > 0
                ? round($mastered->count() / $skills->count() * 100)
                : 0,
        ];
    }

    private function hasMastered(int $userId, Skill $skill): bool
    {
        return StudentSkill::where('user_id', $userId)
            ->where('skill_id', $skill->id)
            ->where('status', 'mastered')
            ->exists();
    }

    /**
     * Find path between two skills using BFS up the dependent chain.
     */
    private function pathBetween(Skill $from, Skill $to): Collection
    {
        if ($from->id === $to->id) {
            return collect([$from]);
        }

        $visited = [$from->id];
        $queue = [[$from]];

        while (!empty($queue)) {
            $path = array_shift($queue);
            $current = end($path);

            foreach ($current->dependents as $dependent) {
                if (in_array($dependent->id, $visited)) {
                    continue;
                }

                $newPath = array_merge($path, [$dependent]);

                if ($dependent->id === $to->id) {
                    return collect($newPath);
                }

                $visited[] = $dependent->id;
                $queue[] = $newPath;
            }
        }

        // No path found — return just the target
        return collect([$from, $to]);
    }
}
