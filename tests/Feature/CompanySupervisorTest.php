<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Company Supervisor (website Company Management): one per company,
 * picked from active Supervisor accounts, replacing the old Contact Person
 * and supervisor roster. Every student placed at the company follows it.
 */
class CompanySupervisorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => $roleId, 'status' => 'active']);
    }

    private function payload(Company $company, array $overrides = []): array
    {
        return array_merge([
            'company_name' => $company->company_name,
            'address' => $company->address,
            'contact_number' => $company->contact_number,
            'email' => $company->email,
            'industry' => $company->industry,
            'slots' => $company->slots,
            'supervisor_id' => $company->supervisor_id,
        ], $overrides);
    }

    public function test_coordinator_creates_a_company_with_a_supervisor_and_no_contact_person(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR);

        $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->post('/company-management', [
                'company_name' => 'Acme',
                'slots' => 3,
                'supervisor_id' => $supervisor->id,
                'contact_person' => 'Ignored Person',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('company-management.index', absolute: false));

        $this->assertDatabaseHas('companies', ['company_name' => 'Acme', 'supervisor_id' => $supervisor->id, 'status' => 'active']);
        $this->assertFalse(Schema::hasColumn('companies', 'contact_person'));
        $this->assertFalse(Schema::hasTable('company_supervisors'));
    }

    public function test_a_company_can_be_created_without_a_supervisor(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->post('/company-management', ['company_name' => 'Acme', 'slots' => 3, 'supervisor_id' => ''])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['company_name' => 'Acme', 'supervisor_id' => null]);
    }

    public function test_only_an_active_supervisor_account_can_be_picked(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        foreach ([User::ROLE_STUDENT, User::ROLE_COORDINATOR, User::ROLE_ADMIN] as $roleId) {
            $this->actingAs($coordinator)
                ->post('/company-management', ['company_name' => 'Acme', 'slots' => 3, 'supervisor_id' => $this->user($roleId)->id])
                ->assertSessionHasErrors(['supervisor_id' => 'The selected supervisor id is invalid.']);
        }

        $this->actingAs($coordinator)
            ->post('/company-management', ['company_name' => 'Acme', 'slots' => 3, 'supervisor_id' => 999999])
            ->assertSessionHasErrors('supervisor_id');

        $this->actingAs($coordinator)
            ->post('/company-management', ['company_name' => 'Acme', 'slots' => 3, 'supervisor_id' => $this->user(User::ROLE_SUPERVISOR, ['status' => 'inactive'])->id])
            ->assertSessionHasErrors(['supervisor_id' => 'This supervisor\'s account is inactive. Activate it in User Management before assigning it to a company.']);

        $this->assertSame(0, Company::count());
    }

    public function test_changing_the_supervisor_moves_every_student_at_the_company(): void
    {
        $old = $this->user(User::ROLE_SUPERVISOR);
        $new = $this->user(User::ROLE_SUPERVISOR, ['name' => 'New Boss']);
        $company = Company::factory()->create(['slots' => 5, 'supervisor_id' => $old->id]);
        $students = Student::factory()->count(2)->create(['company_id' => $company->id, 'supervisor_id' => $old->id]);
        $elsewhere = Student::factory()->create(['supervisor_id' => $old->id, 'company_id' => Company::factory()->create(['supervisor_id' => $old->id])->id]);

        $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->patch("/company-management/{$company->id}", $this->payload($company, ['supervisor_id' => $new->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Company updated successfully. 2 student(s) moved to New Boss.');

        foreach ($students as $student) {
            $this->assertSame($new->id, $student->fresh()->supervisor_id);
            $this->assertDatabaseHas('notifications', ['user_id' => $student->user_id, 'type' => 'assignment_updated']);
        }

        $this->assertSame(2, Notification::where('user_id', $new->id)->where('type', 'assignment_updated')->count());
        $this->assertSame($old->id, $elsewhere->fresh()->supervisor_id, 'Other companies are untouched.');
    }

    public function test_editing_other_fields_keeps_the_supervisor_and_notifies_nobody(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $company = Company::factory()->create(['slots' => 5, 'supervisor_id' => $supervisor->id]);
        Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->patch("/company-management/{$company->id}", $this->payload($company, ['industry' => 'Retail']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Company updated successfully.');

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'industry' => 'Retail', 'supervisor_id' => $supervisor->id]);
        $this->assertSame(0, Notification::count());
    }

    public function test_a_supervisor_can_cover_several_companies(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $a = Company::factory()->create(['supervisor_id' => $supervisor->id]);
        $b = Company::factory()->create();

        $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->patch("/company-management/{$b->id}", $this->payload($b, ['supervisor_id' => $supervisor->id]))
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $supervisor->supervisedCompanies()->pluck('id')->all());
    }

    public function test_deleting_the_supervisor_account_leaves_the_company_without_one(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $company = Company::factory()->create(['supervisor_id' => $supervisor->id]);

        $supervisor->delete();

        $this->assertNull($company->fresh()->supervisor_id);
    }

    public function test_supervisors_and_students_cannot_manage_companies(): void
    {
        $company = Company::factory()->create();

        foreach ([User::ROLE_SUPERVISOR, User::ROLE_STUDENT] as $roleId) {
            $user = $this->user($roleId);

            $this->actingAs($user)->get('/company-management')->assertForbidden();
            $this->actingAs($user)
                ->patch("/company-management/{$company->id}", $this->payload($company, ['supervisor_id' => $this->user(User::ROLE_SUPERVISOR)->id]))
                ->assertForbidden();
        }

        $this->assertNull($company->fresh()->supervisor_id);
    }

    public function test_index_exposes_each_companys_supervisor_and_the_options(): void
    {
        $maria = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Maria']);
        $ana = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Ana']);
        $this->user(User::ROLE_SUPERVISOR, ['name' => 'Inactive', 'status' => 'inactive']);
        Company::factory()->create(['company_name' => 'Acme', 'supervisor_id' => $maria->id]);
        Company::factory()->create(['company_name' => 'Zeta']);

        $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->get('/company-management')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompanyManagement/Index')
                ->missing('availableSupervisors')
                ->where('companies.0.company_name', 'Acme')
                ->where('companies.0.supervisor.name', 'Maria')
                ->where('companies.0.supervisor_issue', null)
                ->missing('companies.0.contact_person')
                ->where('companies.1.supervisor', null)
                ->where('companies.1.supervisor_issue', 'no_supervisor')
                ->where('supervisorOptions', fn ($options) => collect($options)->pluck('name')->all() === ['Ana', 'Maria']));
    }

    public function test_the_migration_turns_the_earliest_roster_entry_into_the_company_supervisor(): void
    {
        $first = $this->user(User::ROLE_SUPERVISOR);
        $second = $this->user(User::ROLE_SUPERVISOR);
        $company = Company::factory()->create();
        $empty = Company::factory()->create();
        $placed = Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $second->id]);
        $unplaced = Student::factory()->create(['supervisor_id' => $second->id]);
        $atEmpty = Student::factory()->create(['company_id' => $empty->id, 'supervisor_id' => $second->id]);

        $migration = include database_path('migrations/22_replace_company_roster_with_company_supervisor.php');
        $migration->down();

        $this->assertTrue(Schema::hasColumn('companies', 'contact_person'));
        DB::table('company_supervisors')->insert([
            ['company_id' => $company->id, 'user_id' => $first->id, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $company->id, 'user_id' => $second->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration->up();

        $this->assertSame($first->id, $company->fresh()->supervisor_id);
        $this->assertNull($empty->fresh()->supervisor_id);
        $this->assertSame($first->id, $placed->fresh()->supervisor_id, 'Placed students follow their company.');
        $this->assertNull($atEmpty->fresh()->supervisor_id);
        $this->assertSame($second->id, $unplaced->fresh()->supervisor_id, 'Unplaced students keep theirs.');
        $this->assertFalse(Schema::hasColumn('companies', 'contact_person'));
        $this->assertFalse(Schema::hasTable('company_supervisors'));
    }
}
