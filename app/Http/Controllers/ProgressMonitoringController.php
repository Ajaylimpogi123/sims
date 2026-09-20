<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProgressMonitoringController extends Controller
{
    public function index(): Response
    {
        $students = Student::query()
            ->with(['user:id,name', 'company:id,company_name'])
            ->withSum('attendances as total_rendered_hours', 'rendered_hours')
            ->when(
                Auth::user()->role_id === 3,
                fn ($query) => $query->where('supervisor_id', Auth::id()),
            )
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('ProgressMonitoring/Index', [
            'students' => $students,
        ]);
    }
}
