<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Management (Coordinator, Administrator). The rules live in
 * CompanyManagementService, shared with the mobile API.
 */
class CompanyController extends Controller
{
    public function __construct(private CompanyManagementService $companies) {}

    public function index(Request $request): Response
    {
        $companies = $this->companies->query($request->only(['search', 'status']))
            ->with('supervisors:id,name,email')
            ->get();

        $availableSupervisors = $this->companies->supervisors()->get(['id', 'name', 'email']);

        return Inertia::render('CompanyManagement/Index', [
            'companies' => $companies,
            'availableSupervisors' => $availableSupervisors,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->companies->create($request->validate($this->companies->rules()));

        return redirect()->route('company-management.index')
            ->with('success', 'Company added successfully.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->companies->update($company, $request->validate($this->companies->rules($company)));

        return redirect()->route('company-management.index')
            ->with('success', 'Company updated successfully.');
    }

    public function toggleStatus(Company $company): RedirectResponse
    {
        $this->companies->setStatus($company, $company->status !== 'active');

        return redirect()->route('company-management.index')
            ->with('success', 'Company status updated.');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $blocker = $this->companies->delete($company);

        if ($blocker !== null) {
            return redirect()->route('company-management.index')
                ->with('error', CompanyManagementService::DELETE_MESSAGES[$blocker]);
        }

        return redirect()->route('company-management.index')
            ->with('success', 'Company deleted successfully.');
    }

    public function attachSupervisor(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate($this->companies->attachRules());

        $this->companies->attachSupervisor($company, (int) $validated['user_id']);

        return redirect()->route('company-management.index')
            ->with('success', 'Supervisor added to company roster.');
    }

    public function detachSupervisor(Company $company, User $user): RedirectResponse
    {
        $this->companies->detachSupervisor($company, $user);

        return redirect()->route('company-management.index')
            ->with('success', 'Supervisor removed from company roster.');
    }
}
