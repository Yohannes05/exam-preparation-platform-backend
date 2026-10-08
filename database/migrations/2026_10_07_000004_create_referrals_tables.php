<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('referred_student_id')->unique()->constrained('students')->cascadeOnDelete();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamps();
            $table->index(['referrer_student_id', 'qualified_at']);
        });

        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_student_id')->constrained('students')->cascadeOnDelete();
            $table->unsignedInteger('referral_milestone');
            $table->timestamp('activated_until');
            $table->timestamps();
            $table->unique(['referrer_student_id', 'referral_milestone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referrals');
    }
};
