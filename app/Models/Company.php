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
        'contact_person',
        'contact_number',
        'email',
        'industry',
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

    public function supervisors()
    {
        return $this->belongsToMany(User::class, 'company_supervisors')->withTimestamps();
    }
}
