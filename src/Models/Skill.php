<?php

namespace KuboKolibri\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $guarded = [];

    public function school()
    {
        return $this->belongsTo(\App\Models\School::class);
    }

    public function subject()
    {
        return $this->belongsTo(\App\Models\Subject::class);
    }

    public function grade()
    {
        return $this->belongsTo(\App\Models\Grade::class);
    }

    /**
     * Skills that this skill requires (prerequisites).
     * "Area of rectangle" requires "Multiplication"
     */
    public function prerequisites()
    {
        return $this->belongsToMany(self::class, 'skill_edges', 'skill_id', 'prerequisite_id');
    }

    /**
     * Skills that require this skill (dependents).
     * "Multiplication" is required by "Area of rectangle"
     */
    public function dependents()
    {
        return $this->belongsToMany(self::class, 'skill_edges', 'prerequisite_id', 'skill_id');
    }

    /**
     * Kolibri content mapped to this skill.
     */
    public function content()
    {
        return $this->belongsToMany(CurriculumMap::class, 'skill_content')
            ->withPivot('role');
    }

    public function practiceContent()
    {
        return $this->content()->wherePivot('role', 'practice');
    }

    public function teachContent()
    {
        return $this->content()->wherePivot('role', 'teach');
    }

    /**
     * Student mastery records for this skill.
     */
    public function studentSkills()
    {
        return $this->hasMany(StudentSkill::class);
    }
}
