<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(): Response
    {
        $students = Student::query()
            ->with(['user:id,name,email,status', 'company:id,company_name'])
            ->orderBy('created_at', 'desc')
            ->get();

        $companies = Company::query()
            ->withCount('students')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'slots']);

        return Inertia::render('StudentManagement/Index', [
            'students' => $students,
            'companies' => $companies,
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $request->merge(['company_id' => $request->company_id ?: null]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$student->user_id],
            'student_number' => ['required', 'string', 'max:50', 'unique:students,student_number,'.$student->id],
            'course' => ['required', 'string', 'max:255'],
            'section' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'internship_schedule' => ['nullable', 'string', 'max:255'],
        ]);

        if (
            $validated['company_id']
            && (int) $validated['company_id'] !== (int) $student->company_id
        ) {
            $company = Company::findOrFail($validated['company_id']);

            $assignedCount = Student::where('company_id', $company->id)->count();

            if ($assignedCount >= $company->slots) {
                return back()
                    ->withErrors([
                        'company_id' => "{$company->company_name} has no available slots ({$assignedCount}/{$company->slots} filled).",
                    ])
                    ->withInput();
            }
        }

        DB::transaction(function () use ($student, $validated) {
            $student->user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
            ]);

            $student->update([
                'student_number' => $validated['student_number'],
                'course' => $validated['course'],
                'section' => $validated['section'],
                'company_id' => $validated['company_id'],
                'internship_schedule' => $validated['internship_schedule'],
            ]);
        });

        return redirect()->route('student-management.index')
            ->with('success', 'Student updated successfully.');
    }

    public function toggleStatus(Student $student): RedirectResponse
    {
        $student->user->update([
            'status' => $student->user->status === 'active' ? 'inactive' : 'active',
        ]);

        return redirect()->route('student-management.index')
            ->with('success', 'Student status updated.');
    }
}
