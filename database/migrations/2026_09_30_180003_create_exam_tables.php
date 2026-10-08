<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type')->default('chapter_test'); // chapter_test | mock
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('question_count')->default(10);
            $table->unsignedInteger('time_limit_minutes')->default(20);
            $table->unsignedSmallInteger('pass_mark')->default(50); // percentage
            // Mock exams: {"chapters": [1,2], "difficulties": {"easy": 3, "medium": 5, "hard": 2}}
            $table->json('distribution')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['exam_id', 'question_id']);
        });

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            // SQLite permits forward references, but PostgreSQL and MySQL do
            // not. Add this FK in the later migration after students exists.
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            } else {
                $table->unsignedBigInteger('student_id');
            }
            $table->string('status')->default('in_progress'); // in_progress | completed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('total_questions')->default(0);
            $table->unsignedInteger('correct_answers')->default(0);
            $table->unsignedSmallInteger('score')->default(0); // percentage
            $table->boolean('passed')->default(false);
            // {"12": {"option": "B", "correct": true}, ...}
            $table->json('answers')->nullable();
            $table->unsignedInteger('time_spent_seconds')->default(0);
            $table->timestamps();

            $table->index(['student_id', 'exam_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('exams');
    }
};
