<?php

namespace KuboKolibri\Console;

use App\Models\Offering;
use App\Models\School;
use App\Models\Schoolyear;
use Illuminate\Console\Command;
use KuboKolibri\Services\KolibriProvisioner;

class ProvisionCommand extends Command
{
    protected $signature = 'kolibri:provision
        {--schoolyear= : Schoolyear id to provision (default: current)}
        {--all : Provision every schoolyear (historical too) — usually not needed}
        {--dry-run : Report what would be provisioned without writing}';

    protected $description = 'Provision schools, classrooms, and learners in Kolibri (idempotent).';

    public function handle(KolibriProvisioner $provisioner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');
        $schoolyearId = $this->option('schoolyear');
        $totals = ['schools' => 0, 'classrooms' => 0, 'learners' => 0];

        if (!$all && !$schoolyearId) {
            $current = Schoolyear::current();
            if (!$current) {
                $this->error('No current schoolyear (no schoolyear spans today). Pass --schoolyear=<id> or --all.');
                return self::FAILURE;
            }
            $schoolyearId = $current->id;
            $this->line("Scope: current schoolyear ({$current->name})");
        } elseif ($all) {
            $this->line('Scope: all schoolyears');
        } else {
            $this->line("Scope: schoolyear id {$schoolyearId}");
        }

        $schools = School::all();
        if ($schools->count() > 1) {
            $this->warn('Multiple schools detected — KUBO Kolibri integration assumes one. Continuing with the first school.');
        }

        $school = $schools->first();
        if (!$school) {
            $this->error('No school found. Run db:seed first.');
            return self::FAILURE;
        }

        $this->info("School: {$school->name}");
        $facilityId = $school->kolibri_facility_id;
        if ($facilityId) {
            $this->line("  facility: {$facilityId} (already provisioned)");
        } elseif ($dryRun) {
            $this->line("  facility: [dry-run] would provision");
            $totals['schools']++;
        } else {
            $facilityId = $provisioner->provisionSchool($school);
            if (!$facilityId) {
                $this->error("  failed to provision facility — aborting");
                return self::FAILURE;
            }
            $this->line("  facility: {$facilityId}");
            $totals['schools']++;
        }

        $offerings = Offering::query()
            ->when($schoolyearId, fn($q) => $q->where('schoolyear_id', $schoolyearId))
            ->with(['enrollments.student', 'grade', 'schoolyear'])
            ->get();

        foreach ($offerings as $offering) {
            $label = trim(($offering->grade->name ?? '?') . ' ' . ($offering->schoolyear->name ?? ''));
            $newClassroom = !$offering->kolibri_classroom_id;
            $unprovisioned = $offering->enrollments
                ->filter(fn($e) => $e->student && !$e->student->kolibri_user_id)
                ->count();

            if (!$newClassroom && $unprovisioned === 0) {
                $this->line("  {$label}: ok");
                continue;
            }

            $parts = [];
            if ($newClassroom) $parts[] = 'classroom';
            if ($unprovisioned > 0) $parts[] = "{$unprovisioned} learner(s)";
            $work = implode(' + ', $parts);

            if ($dryRun || !$facilityId) {
                $this->line("  {$label}: [dry-run] would provision {$work}");
                if ($newClassroom) $totals['classrooms']++;
                $totals['learners'] += $unprovisioned;
                continue;
            }

            $before = $offering->enrollments->filter(fn($e) => $e->student?->kolibri_user_id)->count();
            $provisioner->provisionOffering($offering, $facilityId);
            $offering->refresh()->load('enrollments.student');
            $after = $offering->enrollments->filter(fn($e) => $e->student?->kolibri_user_id)->count();

            if ($newClassroom) $totals['classrooms']++;
            $totals['learners'] += ($after - $before);
            $this->line("  {$label}: provisioned {$work}");
        }

        $this->info('');
        $prefix = $dryRun ? '[dry-run] would provision: ' : 'Provisioned: ';
        $this->info("{$prefix}{$totals['schools']} facility, {$totals['classrooms']} classrooms, {$totals['learners']} learners");

        return self::SUCCESS;
    }
}
