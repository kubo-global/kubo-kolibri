<?php

namespace KuboKolibri\Services;

use Illuminate\Support\Facades\DB;
use App\Domain\Learning\Models\AuthoredExercise;
use KuboKolibri\Models\CurriculumMap;
use PDO;

class ChannelGenerator
{
    private PerseusGenerator $perseus;
    private string $contentPath;

    public function __construct(PerseusGenerator $perseus, ?string $contentPath = null)
    {
        $this->perseus = $perseus;
        $this->contentPath = $contentPath ?? config('kubo-kolibri.kolibri_content_path');
    }

    /**
     * Generate the full Kolibri channel for a school.
     *
     * Creates the SQLite channel database and all Perseus zip files,
     * then upserts CurriculumMap + skill_content rows so the existing
     * learn flow picks up the authored exercises.
     */
    public function generate(int $schoolId): array
    {
        $exercises = AuthoredExercise::where('school_id', $schoolId)
            ->with(['questions', 'subject', 'skill'])
            ->get();

        if ($exercises->isEmpty()) {
            return ['exercises' => 0, 'channel_id' => null];
        }

        $channelId = md5("kubo-school-{$schoolId}");

        // Build the content tree structure grouped by subject
        $tree = $this->buildTree($exercises, $schoolId, $channelId);

        // Generate Perseus files and collect file metadata
        $perseusFiles = $this->generatePerseusFiles($exercises);

        // Create the SQLite channel database
        $dbPath = $this->ensureDir("{$this->contentPath}/databases") . "/{$channelId}.sqlite3";
        $this->buildDatabase($dbPath, $channelId, $tree, $perseusFiles, $schoolId);

        // Write Perseus zips to storage
        foreach ($perseusFiles as $hash => $data) {
            $dir = $this->ensureDir("{$this->contentPath}/storage/" . substr($hash, 0, 1) . '/' . substr($hash, 0, 2));
            file_put_contents("{$dir}/{$hash}.perseus", $data['content']);
        }

        // Upsert CurriculumMap + skill_content for each exercise
        $this->syncCurriculumMaps($exercises, $channelId, $schoolId);

        // Mark exercises as synced
        AuthoredExercise::where('school_id', $schoolId)
            ->update(['channel_synced_at' => now()]);

        return [
            'exercises' => $exercises->count(),
            'channel_id' => $channelId,
            'db_path' => $dbPath,
        ];
    }

    /**
     * Build the MPTT tree structure.
     *
     * Root → Subject topics → Grade topics → Exercise nodes
     */
    private function buildTree($exercises, int $schoolId, string $channelId): array
    {
        $rootId = $channelId;
        $nodes = [];

        // Root node
        $nodes[] = [
            'id' => $rootId,
            'title' => 'KUBO School Exercises',
            'content_id' => $rootId,
            'kind' => 'topic',
            'parent_id' => null,
            'channel_id' => $channelId,
        ];

        // Group exercises by subject, then by grade (via skill)
        $bySubject = $exercises->groupBy('subject_id');

        foreach ($bySubject as $subjectId => $subjectExercises) {
            $subject = $subjectExercises->first()->subject;
            $subjectNodeId = md5("kubo-topic-subject-{$subjectId}");

            $nodes[] = [
                'id' => $subjectNodeId,
                'title' => $subject->name ?? "Subject {$subjectId}",
                'content_id' => $subjectNodeId,
                'kind' => 'topic',
                'parent_id' => $rootId,
                'channel_id' => $channelId,
            ];

            // Group by grade if skills have grade_id
            $byGrade = $subjectExercises->groupBy(fn ($ex) => $ex->skill?->grade_id ?? 0);

            foreach ($byGrade as $gradeId => $gradeExercises) {
                $parentForExercises = $subjectNodeId;

                if ($gradeId > 0) {
                    $gradeNodeId = md5("kubo-topic-{$subjectId}-{$gradeId}");
                    $grade = $gradeExercises->first()->skill?->grade;

                    $nodes[] = [
                        'id' => $gradeNodeId,
                        'title' => $grade->name ?? "Grade {$gradeId}",
                        'content_id' => $gradeNodeId,
                        'kind' => 'topic',
                        'parent_id' => $subjectNodeId,
                        'channel_id' => $channelId,
                    ];

                    $parentForExercises = $gradeNodeId;
                }

                foreach ($gradeExercises as $i => $exercise) {
                    $nodeId = md5("kubo-exercise-{$exercise->id}");
                    $contentId = md5("kubo-content-{$exercise->id}");

                    $exercise->kolibri_node_id = $nodeId;
                    $exercise->kolibri_content_id = $contentId;
                    $exercise->save();

                    $nodes[] = [
                        'id' => $nodeId,
                        'title' => $exercise->title,
                        'content_id' => $contentId,
                        'kind' => 'exercise',
                        'parent_id' => $parentForExercises,
                        'channel_id' => $channelId,
                        'exercise' => $exercise,
                        'sort_order' => $i,
                    ];
                }
            }
        }

        return $this->computeMptt($nodes);
    }

    /**
     * Compute MPTT lft/rght values via DFS.
     */
    private function computeMptt(array $nodes): array
    {
        // Index by id
        $byId = [];
        $children = [];
        foreach ($nodes as &$node) {
            $byId[$node['id']] = &$node;
            $parentId = $node['parent_id'] ?? '__root__';
            $children[$parentId][] = $node['id'];
        }
        unset($node);

        $counter = 1;
        $this->mpttDfs($byId, $children, $nodes[0]['id'], $counter, 0);

        return array_values($byId);
    }

    private function mpttDfs(array &$byId, array &$children, string $nodeId, int &$counter, int $level): void
    {
        $byId[$nodeId]['lft'] = $counter++;
        $byId[$nodeId]['level'] = $level;
        $byId[$nodeId]['tree_id'] = 1;

        foreach ($children[$nodeId] ?? [] as $childId) {
            $this->mpttDfs($byId, $children, $childId, $counter, $level + 1);
        }

        $byId[$nodeId]['rght'] = $counter++;
    }

    /**
     * Generate Perseus zip files for all exercises.
     *
     * Returns [md5hash => ['content' => raw_bytes, 'size' => int, 'exercise_id' => int]]
     */
    private function generatePerseusFiles($exercises): array
    {
        $files = [];

        foreach ($exercises as $exercise) {
            $content = $this->perseus->generate($exercise);
            $hash = md5($content);

            $files[$hash] = [
                'content' => $content,
                'size' => strlen($content),
                'exercise_id' => $exercise->id,
                'node_id' => $exercise->kolibri_node_id,
            ];
        }

        return $files;
    }

    /**
     * Build the Kolibri channel SQLite database.
     */
    private function buildDatabase(string $dbPath, string $channelId, array $tree, array $perseusFiles, int $schoolId): void
    {
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }

        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');

        $this->createSchema($pdo);
        $this->insertMigrations($pdo);
        $this->insertLanguage($pdo);
        $this->insertLicense($pdo);
        $this->insertChannelMetadata($pdo, $channelId, $tree);
        $this->insertContentNodes($pdo, $tree);
        $this->insertAssessmentMetadata($pdo, $tree);
        $this->insertFiles($pdo, $tree, $perseusFiles);
        $this->insertPrerequisiteEdges($pdo, $tree);
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS django_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                app VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                applied DATETIME NOT NULL
            );

            CREATE TABLE IF NOT EXISTS content_language (
                id VARCHAR(14) PRIMARY KEY,
                lang_code VARCHAR(3) NOT NULL,
                lang_subcode VARCHAR(10),
                lang_name VARCHAR(100) NOT NULL,
                lang_direction VARCHAR(3) NOT NULL DEFAULT 'ltr'
            );

            CREATE TABLE IF NOT EXISTS content_license (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                license_name VARCHAR(200) NOT NULL,
                license_description TEXT
            );

            CREATE TABLE IF NOT EXISTS content_contentnode (
                id CHAR(32) PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                content_id CHAR(32) NOT NULL,
                channel_id CHAR(32) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                sort_order REAL NOT NULL DEFAULT 0,
                license_owner VARCHAR(200) NOT NULL DEFAULT '',
                author VARCHAR(200) NOT NULL DEFAULT '',
                license_id INTEGER REFERENCES content_license(id),
                kind VARCHAR(200) NOT NULL,
                available INTEGER NOT NULL DEFAULT 1,
                parent_id CHAR(32) REFERENCES content_contentnode(id),
                lft INTEGER NOT NULL,
                rght INTEGER NOT NULL,
                tree_id INTEGER NOT NULL,
                level INTEGER NOT NULL,
                lang_id VARCHAR(14) REFERENCES content_language(id),
                options TEXT,
                coach_content INTEGER NOT NULL DEFAULT 0
            );

            CREATE INDEX idx_contentnode_parent ON content_contentnode(parent_id);
            CREATE INDEX idx_contentnode_channel ON content_contentnode(channel_id);
            CREATE INDEX idx_contentnode_content ON content_contentnode(content_id);
            CREATE INDEX idx_contentnode_kind ON content_contentnode(kind);
            CREATE INDEX idx_contentnode_tree ON content_contentnode(tree_id, lft);

            CREATE TABLE IF NOT EXISTS content_assessmentmetadata (
                id CHAR(32) PRIMARY KEY,
                assessment_item_ids TEXT NOT NULL DEFAULT '[]',
                number_of_assessments INTEGER NOT NULL DEFAULT 0,
                mastery_model TEXT NOT NULL DEFAULT '{}',
                randomize INTEGER NOT NULL DEFAULT 0,
                is_manipulable INTEGER NOT NULL DEFAULT 0,
                contentnode_id CHAR(32) NOT NULL REFERENCES content_contentnode(id)
            );

            CREATE INDEX idx_assessment_node ON content_assessmentmetadata(contentnode_id);

            CREATE TABLE IF NOT EXISTS content_localfile (
                id CHAR(32) PRIMARY KEY,
                available INTEGER NOT NULL DEFAULT 1,
                file_size BIGINT NOT NULL DEFAULT 0,
                extension VARCHAR(40) NOT NULL
            );

            CREATE TABLE IF NOT EXISTS content_file (
                id CHAR(32) PRIMARY KEY,
                supplementary INTEGER NOT NULL DEFAULT 0,
                thumbnail INTEGER NOT NULL DEFAULT 0,
                priority INTEGER,
                preset VARCHAR(150) NOT NULL DEFAULT '',
                lang_id VARCHAR(14) REFERENCES content_language(id),
                contentnode_id CHAR(32) NOT NULL REFERENCES content_contentnode(id),
                local_file_id CHAR(32) NOT NULL REFERENCES content_localfile(id)
            );

            CREATE INDEX idx_file_node ON content_file(contentnode_id);
            CREATE INDEX idx_file_local ON content_file(local_file_id);

            CREATE TABLE IF NOT EXISTS content_channelmetadata (
                id CHAR(32) PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                author VARCHAR(400) NOT NULL DEFAULT '',
                version INTEGER NOT NULL DEFAULT 1,
                thumbnail TEXT NOT NULL DEFAULT '',
                last_updated DATETIME,
                min_schema_version VARCHAR(50) NOT NULL DEFAULT '1',
                root_id CHAR(32) NOT NULL REFERENCES content_contentnode(id),
                total_resource_count INTEGER NOT NULL DEFAULT 0,
                published_size BIGINT NOT NULL DEFAULT 0,
                partial INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS content_contenttag (
                id CHAR(32) PRIMARY KEY,
                tag_name VARCHAR(30) NOT NULL
            );

            CREATE TABLE IF NOT EXISTS content_contentnode_tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contentnode_id CHAR(32) NOT NULL REFERENCES content_contentnode(id),
                contenttag_id CHAR(32) NOT NULL REFERENCES content_contenttag(id)
            );

            CREATE TABLE IF NOT EXISTS content_contentnode_related (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                from_contentnode_id CHAR(32) NOT NULL REFERENCES content_contentnode(id),
                to_contentnode_id CHAR(32) NOT NULL REFERENCES content_contentnode(id)
            );

            CREATE TABLE IF NOT EXISTS content_contentnode_has_prerequisite (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                from_contentnode_id CHAR(32) NOT NULL REFERENCES content_contentnode(id),
                to_contentnode_id CHAR(32) NOT NULL REFERENCES content_contentnode(id)
            );
        ");
    }

    private function insertMigrations(PDO $pdo): void
    {
        $now = now()->toDateTimeString();
        $stmt = $pdo->prepare('INSERT INTO django_migrations (app, name, applied) VALUES (?, ?, ?)');

        for ($i = 1; $i <= 23; $i++) {
            $name = sprintf('0%03d_auto', $i);
            $stmt->execute(['content', $name, $now]);
        }
    }

    private function insertLanguage(PDO $pdo): void
    {
        $pdo->prepare('INSERT INTO content_language (id, lang_code, lang_subcode, lang_name, lang_direction) VALUES (?, ?, ?, ?, ?)')
            ->execute(['en', 'en', null, 'English', 'ltr']);
    }

    private function insertLicense(PDO $pdo): void
    {
        $pdo->prepare('INSERT INTO content_license (id, license_name, license_description) VALUES (?, ?, ?)')
            ->execute([9, 'Special Permissions', 'School-authored content']);
    }

    private function insertChannelMetadata(PDO $pdo, string $channelId, array $tree): void
    {
        $exerciseCount = count(array_filter($tree, fn ($n) => $n['kind'] === 'exercise'));
        $totalSize = 0; // Will be approximate

        $pdo->prepare('INSERT INTO content_channelmetadata (id, name, description, author, version, thumbnail, last_updated, min_schema_version, root_id, total_resource_count, published_size, partial) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $channelId,
                'KUBO School Exercises',
                'Teacher-authored exercises for this school',
                'KUBO',
                1,
                '',
                now()->toDateTimeString(),
                '1',
                $channelId, // root_id = channel_id
                $exerciseCount,
                $totalSize,
                0,
            ]);
    }

    private function insertContentNodes(PDO $pdo, array $tree): void
    {
        $stmt = $pdo->prepare('INSERT INTO content_contentnode (id, title, content_id, channel_id, description, sort_order, license_owner, author, license_id, kind, available, parent_id, lft, rght, tree_id, level, lang_id, options, coach_content) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($tree as $node) {
            $stmt->execute([
                $node['id'],
                $node['title'],
                $node['content_id'],
                $node['channel_id'],
                $node['exercise']->description ?? '',
                $node['sort_order'] ?? 0,
                '',
                'KUBO',
                9, // Special Permissions license
                $node['kind'],
                1, // available
                $node['parent_id'],
                $node['lft'],
                $node['rght'],
                $node['tree_id'],
                $node['level'],
                'en',
                $node['kind'] === 'exercise' ? '{}' : null,
                0,
            ]);
        }
    }

    private function insertAssessmentMetadata(PDO $pdo, array $tree): void
    {
        $stmt = $pdo->prepare('INSERT INTO content_assessmentmetadata (id, assessment_item_ids, number_of_assessments, mastery_model, randomize, is_manipulable, contentnode_id) VALUES (?, ?, ?, ?, ?, ?, ?)');

        foreach ($tree as $node) {
            if ($node['kind'] !== 'exercise' || !isset($node['exercise'])) {
                continue;
            }

            $exercise = $node['exercise'];
            $itemIds = $exercise->questions->map(fn ($q) => $q->getAssessmentItemId())->toArray();

            $masteryModel = json_encode([
                'type' => 'm_of_n',
                'm' => $exercise->mastery_m,
                'n' => $exercise->mastery_n,
            ]);

            $stmt->execute([
                md5("kubo-assessment-{$exercise->id}"),
                json_encode($itemIds),
                count($itemIds),
                $masteryModel,
                $exercise->randomize ? 1 : 0,
                0,
                $node['id'],
            ]);
        }
    }

    private function insertFiles(PDO $pdo, array $tree, array $perseusFiles): void
    {
        $localStmt = $pdo->prepare('INSERT OR IGNORE INTO content_localfile (id, available, file_size, extension) VALUES (?, ?, ?, ?)');
        $fileStmt = $pdo->prepare('INSERT INTO content_file (id, supplementary, thumbnail, priority, preset, lang_id, contentnode_id, local_file_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

        // Map exercise_id to Perseus file hash
        $exerciseToHash = [];
        foreach ($perseusFiles as $hash => $data) {
            $exerciseToHash[$data['exercise_id']] = $hash;
        }

        foreach ($tree as $node) {
            if ($node['kind'] !== 'exercise' || !isset($node['exercise'])) {
                continue;
            }

            $exercise = $node['exercise'];
            $hash = $exerciseToHash[$exercise->id] ?? null;
            if (!$hash) {
                continue;
            }

            $fileData = $perseusFiles[$hash];

            // Local file entry
            $localStmt->execute([
                $hash,
                1, // available
                $fileData['size'],
                'perseus',
            ]);

            // File entry linking node to local file
            $fileId = md5("kubo-file-{$exercise->id}");
            $fileStmt->execute([
                $fileId,
                0, // supplementary
                0, // thumbnail
                null, // priority
                'exercise', // preset
                'en',
                $node['id'],
                $hash,
            ]);
        }
    }

    /**
     * Write skill prerequisite edges into Kolibri's content_contentnode_has_prerequisite table.
     * Maps KUBO's skill_edges to Kolibri's content node relationships.
     */
    private function insertPrerequisiteEdges(PDO $pdo, array $tree): void
    {
        // Build a map of skill_id → node_id for exercise nodes
        $skillToNode = [];
        foreach ($tree as $node) {
            if ($node['kind'] === 'exercise' && isset($node['exercise']) && $node['exercise']->skill_id) {
                $skillToNode[$node['exercise']->skill_id] = $node['id'];
            }
        }

        if (empty($skillToNode)) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO content_contentnode_has_prerequisite (from_contentnode_id, to_contentnode_id) VALUES (?, ?)');

        // Query skill_edges and write matching node relationships
        $edges = DB::table('skill_edges')
            ->whereIn('skill_id', array_keys($skillToNode))
            ->whereIn('prerequisite_id', array_keys($skillToNode))
            ->get();

        foreach ($edges as $edge) {
            $fromNode = $skillToNode[$edge->skill_id] ?? null;
            $toNode = $skillToNode[$edge->prerequisite_id] ?? null;

            if ($fromNode && $toNode) {
                $stmt->execute([$fromNode, $toNode]);
            }
        }
    }

    /**
     * Upsert CurriculumMap + skill_content so existing learn flow works.
     */
    private function syncCurriculumMaps($exercises, string $channelId, int $schoolId): void
    {
        foreach ($exercises as $exercise) {
            $map = CurriculumMap::updateOrCreate(
                [
                    'school_id' => $schoolId,
                    'kolibri_node_id' => $exercise->kolibri_node_id,
                ],
                [
                    'subject_id' => $exercise->subject_id,
                    'topic_id' => null,
                    'kolibri_channel_id' => $channelId,
                    'kolibri_content_id' => $exercise->kolibri_content_id,
                    'content_kind' => 'exercise',
                    'mapped_by' => $exercise->created_by,
                ]
            );

            // Link to skill if present
            if ($exercise->skill_id) {
                DB::table('skill_content')->updateOrInsert(
                    [
                        'skill_id' => $exercise->skill_id,
                        'curriculum_map_id' => $map->id,
                    ],
                    [
                        'role' => 'practice',
                    ]
                );
            }
        }
    }

    private function ensureDir(string $path): string
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }
}
