<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('chosen')->nullable(); // chosen option label
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('point')->default(0); // points earned on this answer (0 or 1)
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['quiz_id', 'is_correct']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_answers');
    }
};
