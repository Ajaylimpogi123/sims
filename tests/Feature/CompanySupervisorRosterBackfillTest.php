<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanySupervisorRosterBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * Re-runs the 12_create_company_supervisors_table migration's down()
     * then up() directly against the current database state, without
     * touching the `migrations` tracking table or any other table — this
     * exercises the exact same backfill code path a real deploy would run,
     * against whatever students/companies/users already exist at that
     * point (simulating "backfill runs against pre-existing legacy data").
     */
    private function rerunMigration(): void
    {
        $migration = include database_path('migrations/12_create_company_supervisors_table.php');
        $migration->down();
        $migration->up();
    }

    public function test_backfill_populates_one_distinct_row_per_existing_company_supervisor_pair(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        // Two students share the same company+supervisor pair — must dedupe.
        Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);
        Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);

        // A distinct pair.
        Student::factory()->create(['company_id' => $otherCompany->id, 'supervisor_id' => $otherSupervisor->id]);

        // Neither company nor supervisor assigned — must not produce a row.
        Student::factory()->create(['company_id' => null, 'supervisor_id' => null]);

        // Company assigned but no supervisor yet — must not produce a row.
        Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => null]);

        $this->rerunMigration();

        $this->assertSame(2, DB::table('company_supervisors')->count());
        $this->assertDatabaseHas('company_supervisors', [
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
        ]);
        $this->assertDatabaseHas('company_supervisors', [
            'company_id' => $otherCompany->id,
            'user_id' => $otherSupervisor->id,
        ]);
    }

    public function test_backfill_handles_a_supervisor_spanning_multiple_companies(): void
    {
        // Mirrors QA's prep dataset: sup1 at company X (x2 students, dedupes
        // to 1 row) and also at company Y (a second, distinct row for the
        // same supervisor); sup2 only at company X (a third row, same
        // company as sup1's first pair, different supervisor); plus a
        // supervisor-without-company and a company-without-supervisor
        // student, neither of which should produce a roster row.
        $companyX = Company::factory()->create();
        $companyY = Company::factory()->create();
        $sup1 = User::factory()->create(['role_id' => 3]);
        $sup2 = User::factory()->create(['role_id' => 3]);

        Student::factory()->create(['company_id' => $companyX->id, 'supervisor_id' => $sup1->id]);
        Student::factory()->create(['company_id' => $companyX->id, 'supervisor_id' => $sup1->id]);
        Student::factory()->create(['company_id' => $companyY->id, 'supervisor_id' => $sup1->id]);
        Student::factory()->create(['company_id' => $companyX->id, 'supervisor_id' => $sup2->id]);
        Student::factory()->create(['company_id' => null, 'supervisor_id' => $sup1->id]);
        Student::factory()->create(['company_id' => $companyY->id, 'supervisor_id' => null]);

        $this->rerunMigration();

        $this->assertSame(3, DB::table('company_supervisors')->count());
        $this->assertDatabaseHas('company_supervisors', ['company_id' => $companyX->id, 'user_id' => $sup1->id]);
        $this->assertDatabaseHas('company_supervisors', ['company_id' => $companyY->id, 'user_id' => $sup1->id]);
        $this->assertDatabaseHas('company_supervisors', ['company_id' => $companyX->id, 'user_id' => $sup2->id]);
    }

    public function test_backfill_produces_no_rows_when_no_students_have_both_company_and_supervisor(): void
    {
        Student::factory()->create(['company_id' => null, 'supervisor_id' => null]);

        $this->rerunMigration();

        $this->assertSame(0, DB::table('company_supervisors')->count());
    }

    public function test_company_supervisors_relationship_reflects_the_backfilled_roster(): void
    {
        $company = Company::factory()->create();
        $supervisor = User::factory()->create(['role_id' => 3]);
        Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);

        $this->rerunMigration();

        $this->assertTrue($company->fresh()->supervisors->contains('id', $supervisor->id));
        $this->assertTrue($supervisor->fresh()->supervisedCompanies->contains('id', $company->id));
    }
}
