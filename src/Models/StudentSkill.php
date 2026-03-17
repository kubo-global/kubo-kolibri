<?php

namespace KuboKolibri\Models;

use Illuminate\Database\Eloquent\Model;

class StudentSkill extends Model
{
    protected $guarded = [];

    protected $casts = [
        'mastery' => 'decimal:2',
        'last_attempted_at' => 'datetime',
        'mastered_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function isMastered(): bool
    {
        return $this->status === 'mastered';
    }

    public function scopeForStudent($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeMastered($query)
    {
        return $query->where('status', 'mastered');
    }
}
