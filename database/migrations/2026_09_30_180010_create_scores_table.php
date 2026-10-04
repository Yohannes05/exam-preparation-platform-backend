<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('week_id')->default(0); // week number (reset Monday 00:00 Addis Ababa)
            $table->unsignedInteger('weekly_points')->default(0);
            $table->unsignedInteger('total_points')->default(0);
            $table->timestamps();

            $table->unique(['student_id', 'week_id']);
            $table->index(['student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
