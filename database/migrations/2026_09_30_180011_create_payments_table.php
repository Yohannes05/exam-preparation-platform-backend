<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // user/student
            $table->string('receipt_photo')->nullable(); // path to receipt photo
            $table->string('method'); // telebirr | cbe_birr
            $table->unsignedInteger('amount')->default(150); // birr
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('valid_until')->nullable(); // 30 days from approval
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
