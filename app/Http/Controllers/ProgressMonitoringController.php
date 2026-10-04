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
            ->monitoredBy(Auth::user())
            ->get();

        return Inertia::render('ProgressMonitoring/Index', [
            'students' => $students,
        ]);
    }
}
