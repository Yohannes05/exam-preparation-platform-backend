<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotSession extends Model
{
    protected $fillable = ['chat_id', 'student_id', 'state', 'context'];

    protected $casts = ['chat_id' => 'integer', 'context' => 'array'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public static function forChat(int $chatId): self
    {
        return static::firstOrCreate(['chat_id' => $chatId]);
    }

    public function setState(string $state, ?array $context = null): void
    {
        $this->state = $state;

        if ($context !== null) {
            $this->context = $context;
        }

        $this->save();
    }

    public function putContext(array $values): void
    {
        $this->context = array_merge($this->context ?? [], $values);
        $this->save();
    }
}
