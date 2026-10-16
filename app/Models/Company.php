<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'address',
        'contact_number',
        'email',
        'industry',
        'supervisor_id',
        'slots',
        'status',
    ];

    public function students()
    {
        return $this->hasMany(Student::class);
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }

    /**
     * The Company Supervisor: every student placed here is supervised by
     * them (students.supervisor_id follows companies.supervisor_id).
     */
    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
