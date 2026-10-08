<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('telegram_payments', 'review_reason')) {
            Schema::table('telegram_payments', function (Blueprint $table) {
                $table->string('review_reason', 200)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('telegram_payments', 'review_reason')) {
            Schema::table('telegram_payments', function (Blueprint $table) {
                $table->dropColumn('review_reason');
            });
        }
    }
};
