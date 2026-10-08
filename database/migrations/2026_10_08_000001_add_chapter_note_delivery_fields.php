<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            if (! Schema::hasColumn('chapters', 'telegraph_url')) $table->text('telegraph_url')->nullable();
            if (! Schema::hasColumn('chapters', 'telegraph_path')) $table->string('telegraph_path')->nullable();
            if (! Schema::hasColumn('chapters', 'pdf_file')) $table->string('pdf_file')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            foreach (['telegraph_url', 'telegraph_path', 'pdf_file'] as $column) {
                if (Schema::hasColumn('chapters', $column)) $table->dropColumn($column);
            }
        });
    }
};
