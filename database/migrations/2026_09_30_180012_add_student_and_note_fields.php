<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('joined_at')->nullable()->after('last_seen_at');
            $table->timestamp('activated_until')->nullable()->after('joined_at');
        });

        Schema::table('notes', function (Blueprint $table) {
            if (! Schema::hasColumn('notes', 'page_number')) {
                $table->unsignedInteger('page_number')->default(1)->after('chapter_id');
            }
            if (! Schema::hasColumn('notes', 'image_file')) {
                $table->string('image_file')->nullable()->after('page_number');
            }
            if (! Schema::hasColumn('notes', 'pdf_file')) {
                $table->string('pdf_file')->nullable()->after('image_file');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['joined_at', 'activated_until']);
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->dropColumn(['page_number', 'image_file', 'pdf_file']);
        });
    }
};
