<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    private const SUPERVISOR_ROLE_ID = 3;

    public function index(Request $request): Response
    {
        $companies = Company::query()
            ->when($request->filled('search'), fn ($query) => $query->where('company_name', 'like', '%'.$request->search.'%'))
            ->when(
                $request->filled('status') && in_array($request->status, ['active', 'inactive'], true),
                fn ($query) => $query->where('status', $request->status),
            )
            ->withCount('students')
            ->with('supervisors:id,name,email')
            ->orderBy('company_name')
            ->get();

        $availableSupervisors = User::query()
            ->where('role_id', self::SUPERVISOR_ROLE_ID)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('CompanyManagement/Index', [
            'companies' => $companies,
            'availableSupervisors' => $availableSupervisors,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'slots' => ['required', 'integer', 'min:0'],
        ]);

        Company::create($validated + ['status' => 'active']);

        return redirect()->route('company-management.index')
            ->with('success', 'Company added successfully.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'slots' => ['required', 'integer', 'min:0'],
        ]);

        $company->update($validated);

        return redirect()->route('company-management.index')
            ->with('success', 'Company updated successfully.');
    }

    public function toggleStatus(Company $company): RedirectResponse
    {
        $company->update([
            'status' => $company->status === 'active' ? 'inactive' : 'active',
        ]);

        return redirect()->route('company-management.index')
            ->with('success', 'Company status updated.');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $company->delete();

        return redirect()->route('company-management.index')
            ->with('success', 'Company deleted successfully.');
    }

    public function attachSupervisor(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => [
                'required',
                Rule::exists('users', 'id')->where('role_id', self::SUPERVISOR_ROLE_ID),
            ],
        ]);

        $company->supervisors()->syncWithoutDetaching([$validated['user_id']]);

        return redirect()->route('company-management.index')
            ->with('success', 'Supervisor added to company roster.');
    }

    public function detachSupervisor(Company $company, User $user): RedirectResponse
    {
        $company->supervisors()->detach($user->id);

        return redirect()->route('company-management.index')
            ->with('success', 'Supervisor removed from company roster.');
    }
}
