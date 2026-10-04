<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // {"language": "en"}
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id')->unique();
            $table->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('state')->default('start'); // state machine state
            $table->json('context')->nullable(); // transient flow data
            $table->timestamps();
        });

        Schema::create('question_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_attempt_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('context')->default('practice'); // practice | test | mock | mistake
            $table->foreignId('chosen_option_id')->nullable()->constrained('question_options')->nullOnDelete();
            $table->boolean('is_correct')->default(false);
            $table->timestamp('answered_at')->useCurrent();
            $table->timestamps();

            $table->index(['student_id', 'question_id']);
        });

        Schema::create('mistakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('wrong_count')->default(1);
            $table->unsignedInteger('right_count')->default(0);
            $table->boolean('is_mastered')->default(false);
            $table->timestamp('last_wrong_at')->useCurrent();
            $table->timestamps();

            $table->unique(['student_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mistakes');
        Schema::dropIfExists('question_attempts');
        Schema::dropIfExists('bot_sessions');
        Schema::dropIfExists('students');
    }
};
