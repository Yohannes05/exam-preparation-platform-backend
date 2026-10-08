<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chapters', 'requires_activation')) {
            Schema::table('chapters', function (Blueprint $table) {
                $table->boolean('requires_activation')->default(false)->after('is_active');
            });
        }

        // Preserve the existing pricing rule on current installations: the
        // first three active chapters in each subject stay free by default.
        DB::table('subjects')->orderBy('id')->pluck('id')->each(function ($subjectId) {
            $lockedIds = DB::table('chapters')
                ->where('subject_id', $subjectId)
                ->where('is_active', true)
                ->orderBy('order')->orderBy('id')
                ->get(['id'])
                ->slice(3)
                ->pluck('id');

            if ($lockedIds->isNotEmpty()) {
                DB::table('chapters')->whereIn('id', $lockedIds)->update(['requires_activation' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn('requires_activation');
        });
    }
};
