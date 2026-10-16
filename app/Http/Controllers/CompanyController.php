<?php

namespace App\Http\Controllers;

use App\Models\Company;
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
        $companies = $this->companies->query($request->only(['search', 'status']))->get();

        // Adds supervisor_issue (and the delete blocker) to each company.
        $this->companies->loadDetails($companies);

        return Inertia::render('CompanyManagement/Index', [
            'companies' => $companies,
            'supervisorOptions' => $this->companies->supervisorOptions(),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->companies->prepare($request);

        $this->companies->create($request->validate($this->companies->rules()));

        return redirect()->route('company-management.index')
            ->with('success', 'Company added successfully.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->companies->prepare($request);

        $moved = $this->companies->update($company, $request->validate($this->companies->rules($company)));

        return redirect()->route('company-management.index')
            ->with('success', 'Company updated successfully.'.CompanyManagementService::movedMessage($moved, $company->load('supervisor')));
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
}
