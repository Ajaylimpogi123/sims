<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner redesign (2026-10-15): the company's "Contact Person" becomes its
 * Company Supervisor. One supervisor per company (a supervisor may cover
 * several companies), and a student's supervisor is always their
 * company's supervisor.
 *
 * - companies.supervisor_id (nullable, null when the user is deleted),
 *   backfilled from the company_supervisors roster (the earliest row per
 *   company);
 * - companies.contact_person and the company_supervisors roster dropped;
 * - students.supervisor_id synced to their company's supervisor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('supervisor_id')->nullable()->after('industry')->constrained('users')->nullOnDelete();
        });

        if (Schema::hasTable('company_supervisors')) {
            $earliest = DB::table('company_supervisors')
                ->selectRaw('company_id, min(id) as id')
                ->groupBy('company_id');

            DB::table('company_supervisors')
                ->joinSub($earliest, 'earliest', 'earliest.id', '=', 'company_supervisors.id')
                ->get(['company_supervisors.company_id', 'company_supervisors.user_id'])
                ->each(fn ($row) => DB::table('companies')
                    ->where('id', $row->company_id)
                    ->update(['supervisor_id' => $row->user_id]));
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('contact_person');
        });

        // Every placed student gets their company's supervisor (none when
        // the company has none); unplaced students keep theirs.
        DB::table('students')
            ->whereNotNull('company_id')
            ->update([
                'supervisor_id' => DB::raw('(select companies.supervisor_id from companies where companies.id = students.company_id)'),
            ]);

        Schema::dropIfExists('company_supervisors');
    }

    public function down(): void
    {
        Schema::create('company_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        $now = now();

        DB::table('companies')->whereNotNull('supervisor_id')->get(['id', 'supervisor_id'])
            ->each(fn ($company) => DB::table('company_supervisors')->insert([
                'company_id' => $company->id,
                'user_id' => $company->supervisor_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        Schema::table('companies', function (Blueprint $table) {
            $table->string('contact_person')->nullable()->after('address');
            $table->dropConstrainedForeignId('supervisor_id');
        });
    }
};
