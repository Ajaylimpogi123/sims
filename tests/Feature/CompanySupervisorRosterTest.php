<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanySupervisorRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_coordinator_can_add_a_supervisor_to_a_companys_roster(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create();
        $supervisor = User::factory()->create(['role_id' => 3]);

        $response = $this->actingAs($coordinator)->post(
            "/company-management/{$company->id}/supervisors",
            ['user_id' => $supervisor->id],
        );

        $response->assertRedirect(route('company-management.index', absolute: false));
        $this->assertDatabaseHas('company_supervisors', [
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
        ]);
    }

    public function test_admin_can_remove_a_supervisor_from_a_companys_roster(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $company = Company::factory()->create();
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company->supervisors()->attach($supervisor->id);

        $response = $this->actingAs($admin)->delete(
            "/company-management/{$company->id}/supervisors/{$supervisor->id}",
        );

        $response->assertRedirect(route('company-management.index', absolute: false));
        $this->assertDatabaseMissing('company_supervisors', [
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
        ]);
    }

    public function test_only_a_role_3_user_can_be_added_to_the_roster(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create();
        $notASupervisor = User::factory()->create(['role_id' => 1]);

        $response = $this->actingAs($coordinator)->post(
            "/company-management/{$company->id}/supervisors",
            ['user_id' => $notASupervisor->id],
        );

        $response->assertSessionHasErrors('user_id');
        $this->assertDatabaseMissing('company_supervisors', [
            'company_id' => $company->id,
            'user_id' => $notASupervisor->id,
        ]);
    }

    public function test_adding_the_same_supervisor_twice_does_not_create_a_duplicate_row(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create();
        $supervisor = User::factory()->create(['role_id' => 3]);

        $this->actingAs($coordinator)->post(
            "/company-management/{$company->id}/supervisors",
            ['user_id' => $supervisor->id],
        );
        $this->actingAs($coordinator)->post(
            "/company-management/{$company->id}/supervisors",
            ['user_id' => $supervisor->id],
        );

        $this->assertSame(
            1,
            DB::table('company_supervisors')
                ->where('company_id', $company->id)
                ->where('user_id', $supervisor->id)
                ->count(),
        );
    }

    public function test_a_supervisor_can_be_on_multiple_company_rosters(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $supervisor = User::factory()->create(['role_id' => 3]);

        $this->actingAs($coordinator)->post(
            "/company-management/{$companyA->id}/supervisors",
            ['user_id' => $supervisor->id],
        );
        $this->actingAs($coordinator)->post(
            "/company-management/{$companyB->id}/supervisors",
            ['user_id' => $supervisor->id],
        );

        $this->assertDatabaseHas('company_supervisors', [
            'company_id' => $companyA->id,
            'user_id' => $supervisor->id,
        ]);
        $this->assertDatabaseHas('company_supervisors', [
            'company_id' => $companyB->id,
            'user_id' => $supervisor->id,
        ]);
    }

    public function test_supervisor_role_cannot_manage_company_rosters(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company = Company::factory()->create();
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        $this->actingAs($supervisor)
            ->post("/company-management/{$company->id}/supervisors", [
                'user_id' => $otherSupervisor->id,
            ])
            ->assertForbidden();
    }

    public function test_index_exposes_each_companys_roster_and_the_available_supervisor_list(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create();
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company->supervisors()->attach($supervisor->id);

        $this->actingAs($coordinator)
            ->get('/company-management')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompanyManagement/Index')
                ->has('availableSupervisors', 1)
                ->where('companies.0.supervisors.0.id', $supervisor->id)
            );
    }
}
