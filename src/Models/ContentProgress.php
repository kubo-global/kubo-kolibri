<?php

namespace KuboKolibri\Models;

use Illuminate\Database\Eloquent\Model;

class ContentProgress extends Model
{
    protected $table = 'content_progress';

    protected $guarded = [];

    protected $casts = [
        'score' => 'decimal:2',
        'completed' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function curriculumMap()
    {
        return $this->belongsTo(CurriculumMap::class);
    }

    public function scopeForStudent($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('completed', true);
    }
}
