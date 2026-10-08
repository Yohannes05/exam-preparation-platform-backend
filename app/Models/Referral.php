<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $fillable = ['referrer_student_id', 'referred_student_id', 'qualified_at'];

    protected $casts = ['qualified_at' => 'datetime'];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'referrer_student_id');
    }

    public function referredStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'referred_student_id');
    }
}
