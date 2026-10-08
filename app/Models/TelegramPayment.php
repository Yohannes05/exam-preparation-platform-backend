<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramPayment extends Model
{
    protected $fillable = [
        'student_id', 'receipt_file_id', 'method', 'amount', 'status', 'reviewed_at', 'valid_until', 'review_reason',
    ];

    protected $casts = [
        'amount' => 'integer',
        'reviewed_at' => 'datetime',
        'valid_until' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
