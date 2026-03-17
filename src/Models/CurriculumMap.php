<?php

namespace KuboKolibri\Models;

use Illuminate\Database\Eloquent\Model;

class CurriculumMap extends Model
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

    public function topic()
    {
        return $this->belongsTo(\App\Models\Topic::class);
    }

    public function mapper()
    {
        return $this->belongsTo(\App\Models\User::class, 'mapped_by');
    }

    public function progress()
    {
        return $this->hasMany(ContentProgress::class);
    }

    public function scopeForSubject($query, $subjectId)
    {
        return $query->where('subject_id', $subjectId);
    }

    public function scopeForTopic($query, $topicId)
    {
        return $query->where('topic_id', $topicId);
    }

    public function scopeExercises($query)
    {
        return $query->where('content_kind', 'exercise');
    }

    public function scopeVideos($query)
    {
        return $query->where('content_kind', 'video');
    }
}
