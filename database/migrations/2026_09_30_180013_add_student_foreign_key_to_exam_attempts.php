<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite created this forward reference in the original migration;
        // PostgreSQL/MySQL require students to exist before adding the FK.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->dropForeign(['student_id']);
            });
        }
    }
};
