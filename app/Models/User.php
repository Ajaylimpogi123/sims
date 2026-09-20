<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'status',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function student()
    {
        return $this->hasOne(Student::class);
    }

    public function supervisedStudents()
    {
        return $this->hasMany(Student::class, 'supervisor_id');
    }

    /**
     * SIMS uses its own lightweight Notification model/table instead of
     * Laravel's polymorphic database notifications, so this intentionally
     * overrides Notifiable::notifications().
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class)->latest();
    }

    public function unreadNotificationsCount(): int
    {
        return $this->notifications()->unread()->count();
    }

    public function dashboardRouteName(): string
    {
        return match ((int) $this->role_id) {
            4 => 'admin-dashboard',
            default => 'dashboard',
        };
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
