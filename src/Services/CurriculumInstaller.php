<?php

namespace KuboKolibri\Services;

use App\Domain\Learning\Models\Skill;
use App\Models\Grade;
use App\Models\School;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KuboKolibri\Models\CurriculumMap;

/**
 * Installs a bundled curriculum fixture (resources/curricula/<slug>.json):
 * the skill graph (skills + prerequisite edges) and its Kolibri content
 * mappings. Idempotent — skills are matched by (school, subject, grade,
 * name) and maps by (school, subject, node id), so re-running updates in
 * place and keeps anything a teacher added locally.
 *
 * Used by the kolibri:install-curriculum command and the Settings UI.
 */
class CurriculumInstaller
{
    public function directory(): string
    {
        return dirname(__DIR__, 2) . '/resources/curricula';
    }

    /**
     * Bundled curricula with install status, keyed by slug.
     *
     * @return array<string, array{name: string, subject: string, skills: int, maps: int, installed: int, state: string}>
     */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->directory() . '/*.json') ?: [] as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $slug = basename($file, '.json');
            $out[$slug] = [
                'name' => $data['name'] ?? $slug,
                'subject' => $data['subject'] ?? '?',
                'skills' => count($data['skills'] ?? []),
                'maps' => count($data['maps'] ?? []),
            ] + $this->status($data);
        }

        return $out;
    }

    public function load(string $slug): ?array
    {
        $path = $this->directory() . '/' . basename($slug) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * How much of the fixture is already present: state is one of
     * 'installed', 'partial', 'none'.
     *
     * @return array{state: string, installed: int}
     */
    public function status(array $data): array
    {
        $school = School::first();
        $subject = $school ? Subject::where('name', $data['subject'] ?? '')->first() : null;
        if (!$school || !$subject) {
            return ['state' => 'none', 'installed' => 0];
        }

        $grades = Grade::pluck('id', 'name');
        $existing = Skill::where('school_id', $school->id)
            ->where('subject_id', $subject->id)
            ->get(['grade_id', 'name'])
            ->map(fn ($s) => $s->grade_id . '|' . $s->name)
            ->flip();

        $found = collect($data['skills'] ?? [])
            ->filter(fn ($s) => $grades->has($s['grade']) && $existing->has($grades[$s['grade']] . '|' . $s['name']))
            ->count();

        $total = count($data['skills'] ?? []);
        $state = $found === 0 ? 'none' : ($found === $total ? 'installed' : 'partial');

        return ['state' => $state, 'installed' => $found];
    }

    /**
     * Install (or update) the fixture. Returns counts:
     * ['created' => n, 'updated' => n, 'unchanged' => n, 'edges' => n, 'links' => n].
     *
     * @throws InvalidArgumentException when the school, subject or grades are missing.
     */
    public function install(array $data, bool $dryRun = false): array
    {
        $school = School::first();
        if (!$school) {
            throw new InvalidArgumentException('No school found. Run db:seed first.');
        }

        $subject = Subject::where('name', $data['subject'])->first();
        if (!$subject) {
            throw new InvalidArgumentException("Subject '{$data['subject']}' not found in this school. Create it in Settings first.");
        }

        $grades = Grade::pluck('id', 'name');
        $missingGrades = collect($data['skills'])->pluck('grade')->unique()->reject(fn ($g) => $grades->has($g));
        if ($missingGrades->isNotEmpty()) {
            throw new InvalidArgumentException('Missing grades: ' . $missingGrades->implode(', ') . '. Set up the academic structure first.');
        }

        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $tally = function ($model) use (&$stats) {
            if ($model->wasRecentlyCreated) {
                $stats['created']++;
            } elseif ($model->wasChanged()) {
                $stats['updated']++;
            } else {
                $stats['unchanged']++;
            }
        };

        DB::beginTransaction();

        $mapsByRef = [];
        foreach ($data['maps'] as $m) {
            $map = CurriculumMap::updateOrCreate(
                [
                    'school_id' => $school->id,
                    'subject_id' => $subject->id,
                    'kolibri_node_id' => $m['node_id'],
                ],
                [
                    'kolibri_channel_id' => $m['channel_id'],
                    'kolibri_content_id' => $m['content_id'],
                    'content_kind' => $m['kind'],
                    'title' => $m['title'] ?? null,
                    'display_order' => $m['display_order'] ?? 0,
                ],
            );
            $tally($map);
            $mapsByRef[$m['ref']] = $map;
        }

        $skillsByRef = [];
        foreach ($data['skills'] as $s) {
            $skill = Skill::updateOrCreate(
                [
                    'school_id' => $school->id,
                    'subject_id' => $subject->id,
                    'grade_id' => $grades[$s['grade']],
                    'name' => $s['name'],
                ],
                [
                    'description' => $s['description'],
                    'level' => $s['level'],
                    'scheduled_week' => $s['scheduled_week'],
                ],
            );
            $tally($skill);
            $skillsByRef[$s['ref']] = $skill;
        }

        foreach ($data['edges'] as $e) {
            $skillsByRef[$e['skill']]->prerequisites()->syncWithoutDetaching([$skillsByRef[$e['prerequisite']]->id]);
        }
        foreach ($data['skill_content'] as $link) {
            $skillsByRef[$link['skill']]->content()->syncWithoutDetaching([
                $mapsByRef[$link['map']]->id => [
                    'role' => $link['role'],
                    'approved' => $link['approved'],
                ],
            ]);
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $stats + ['edges' => count($data['edges']), 'links' => count($data['skill_content'])];
    }
}
