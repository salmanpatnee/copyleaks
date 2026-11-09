<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Our own printed Submission ID number (spec 0012, AC-3).
 * Plain code on purpose: no app classes, so a later change to them cannot break this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_number')->nullable()->after('public_id');
        });

        $used = [];

        DB::table('submissions')->orderBy('id')->chunkById(500, function ($rows) use (&$used): void {
            foreach ($rows as $row) {
                do {
                    $number = random_int(100_000_000, 999_999_999);
                } while (isset($used[$number]));

                $used[$number] = true;

                DB::table('submissions')->where('id', $row->id)->update(['submission_number' => $number]);
            }
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_number')->nullable(false)->change();
            $table->unique('submission_number');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropUnique(['submission_number']);
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('submission_number');
        });
    }
};
