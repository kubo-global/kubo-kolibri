<?php

namespace KuboKolibri\Models;

use Illuminate\Database\Eloquent\Model;

class LessonAssignment extends Model
{
    protected $guarded = [];

    public function offering()
    {
        return $this->belongsTo(\App\Models\Offering::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function teacher()
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_by');
    }

    public function scopeForOffering($query, int $offeringId)
    {
        return $query->where('offering_id', $offeringId);
    }

    public function scopeForWeek($query, int $week)
    {
        return $query->where('week_number', $week);
    }
}
