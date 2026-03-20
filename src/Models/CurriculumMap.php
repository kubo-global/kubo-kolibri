<?php

namespace KuboKolibri\Models;

use Illuminate\Database\Eloquent\Model;
use KuboKolibri\Client\KolibriClient;

class CurriculumMap extends Model
{
    protected $guarded = [];

    /**
     * Lazily resolve and cache the Kolibri content_id from the node_id.
     */
    public function resolveContentId(KolibriClient $client): ?string
    {
        if ($this->kolibri_content_id) {
            return $this->kolibri_content_id;
        }

        $node = $client->getContentNode($this->kolibri_node_id);

        if (!$node || empty($node['content_id'])) {
            return null;
        }

        $this->kolibri_content_id = $node['content_id'];
        $this->save();

        return $this->kolibri_content_id;
    }

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
