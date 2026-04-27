<?php

namespace KuboKolibri\Services;

use App\Models\Offering;
use App\Models\School;
use App\Models\User;
use KuboKolibri\Client\KolibriClient;

class KolibriProvisioner
{
    private KolibriClient $client;
    private string $passwordSecret;

    public function __construct(KolibriClient $client, string $passwordSecret)
    {
        $this->client = $client;
        $this->passwordSecret = $passwordSecret;

        if (empty($passwordSecret)) {
            \Illuminate\Support\Facades\Log::warning('KOLIBRI_LEARNER_SECRET is not set. Set it in .env for secure learner passwords.');
        }
    }

    /**
     * Provision a school as a Kolibri facility.
     * Stores the kolibri_facility_id back on the school.
     */
    public function provisionSchool(School $school): ?string
    {
        if ($school->kolibri_facility_id) {
            return $school->kolibri_facility_id;
        }

        $facility = $this->client->createFacility($school->name);
        if (!$facility || empty($facility['id'])) {
            return null;
        }

        $school->kolibri_facility_id = $facility['id'];
        $school->save();

        return $facility['id'];
    }

    /**
     * Provision an offering (class) as a Kolibri classroom.
     * Requires the school's facility to be provisioned first.
     */
    public function provisionClass(Offering $offering, string $facilityId): ?string
    {
        if ($offering->kolibri_classroom_id) {
            return $offering->kolibri_classroom_id;
        }

        $name = $offering->grade->name;
        if ($offering->schoolyear) {
            $name .= ' ' . $offering->schoolyear->name;
        }

        $classroom = $this->client->createClassroom($facilityId, $name);
        if (!$classroom || empty($classroom['id'])) {
            return null;
        }

        $offering->kolibri_classroom_id = $classroom['id'];
        $offering->save();

        return $classroom['id'];
    }

    /**
     * Provision a student as a Kolibri learner.
     * Assigns them to the classroom if provided.
     */
    public function provisionLearner(User $user, string $facilityId, ?string $classroomId = null): ?string
    {
        if ($user->kolibri_user_id) {
            // Already provisioned — just ensure classroom membership
            if ($classroomId) {
                $this->client->addToClassroom($user->kolibri_user_id, $classroomId);
            }
            return $user->kolibri_user_id;
        }

        $username = $this->kolibriUsername($user);
        $password = $this->kolibriPassword($user);

        $learner = $this->client->createLearner(
            $facilityId,
            $username,
            $user->full_name,
            $password,
        );

        if (!$learner || empty($learner['id'])) {
            return null;
        }

        $user->kolibri_user_id = $learner['id'];
        $user->save();

        if ($classroomId) {
            $this->client->addToClassroom($learner['id'], $classroomId);
        }

        return $learner['id'];
    }

    /**
     * Provision an entire offering: classroom + all enrolled students.
     * Returns count of learners provisioned.
     */
    public function provisionOffering(Offering $offering, string $facilityId): int
    {
        $classroomId = $this->provisionClass($offering, $facilityId);
        if (!$classroomId) {
            return 0;
        }

        $count = 0;
        foreach ($offering->enrollments()->with('student')->get() as $enrollment) {
            if ($enrollment->student && $this->provisionLearner($enrollment->student, $facilityId, $classroomId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Assign content to a Kolibri classroom as a lesson.
     */
    /**
     * Assign content to a Kolibri classroom as a lesson.
     * Pass $learnerKolibriIds to target specific students (Kolibri user IDs).
     */
    public function assignContent(string $classroomId, string $title, array $nodeIds, string $createdBy, array $learnerKolibriIds = []): ?array
    {
        $resources = collect($nodeIds)->map(function ($nodeId, $i) {
            $node = $this->client->getContentNode($nodeId);
            return [
                'contentnode_id' => $nodeId,
                'content_id' => $node['content_id'] ?? $nodeId,
                'channel_id' => $node['channel_id'] ?? '',
                'order' => $i,
            ];
        })->values()->toArray();

        return $this->client->createLesson($classroomId, $title, $resources, $createdBy, $learnerKolibriIds);
    }

    /**
     * Generate the deterministic Kolibri username for a KUBO user.
     */
    public function kolibriUsername(User $user): string
    {
        return 'kubo_' . $user->id;
    }

    /**
     * Generate the deterministic Kolibri password for a KUBO user.
     * Derived from a shared secret + user ID so it never needs to be stored.
     */
    public function kolibriPassword(User $user): string
    {
        return substr(hash('sha256', $this->passwordSecret . $user->id), 0, 16);
    }
}
