<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralReward extends Model
{
    protected $fillable = ['referrer_student_id', 'referral_milestone', 'activated_until'];

    protected $casts = ['activated_until' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'referrer_student_id');
    }
}
