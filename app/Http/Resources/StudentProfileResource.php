<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Summary of the signed-in student's internship profile (GET /api/v1/me).
 *
 * Expects company and supervisor to be eager loaded.
 *
 * @mixin \App\Models\Student
 */
class StudentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_number' => $this->student_number,
            'course' => $this->course,
            'section' => $this->section,
            'internship_status' => $this->internship_status,
            'required_hours' => $this->required_hours === null ? null : (int) $this->required_hours,
            'company' => $this->company ? [
                'id' => $this->company->id,
                'name' => $this->company->company_name,
            ] : null,
            'supervisor' => $this->supervisor ? [
                'id' => $this->supervisor->id,
                'name' => $this->supervisor->name,
            ] : null,
        ];
    }
}
