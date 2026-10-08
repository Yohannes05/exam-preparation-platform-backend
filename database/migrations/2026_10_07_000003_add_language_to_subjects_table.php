<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('language', 2)->default('en')->after('name');
        });

        DB::table('subjects')->whereRaw('LOWER(name) = ?', ['amharic'])->update(['language' => 'am']);
        DB::table('subjects')
            ->whereIn('grade_id', DB::table('grades')->select('id')->where('level', 6))
            ->whereIn(DB::raw('LOWER(name)'), ['general science', 'civics'])
            ->update(['language' => 'am']);
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
