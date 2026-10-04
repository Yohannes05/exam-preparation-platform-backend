<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('mode'); // chapter | random | model | national
            $table->unsignedSmallInteger('year_ec')->nullable(); // 2016, 2017, 2018 E.C.
            $table->foreignId('chapter_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('size')->default(10); // number of questions
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'mode']);
            $table->index(['student_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
