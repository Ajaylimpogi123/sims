<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /** Role IDs — the canonical mapping lives in RoleSeeder. */
    public const ROLE_STUDENT = 1;

    public const ROLE_COORDINATOR = 2;

    public const ROLE_SUPERVISOR = 3;

    public const ROLE_ADMIN = 4;

    /**
     * Role IDs allowed to use the mobile app: every known role. Accounts
     * with a null/unknown role_id are refused.
     */
    public const MOBILE_ROLE_IDS = [
        self::ROLE_STUDENT,
        self::ROLE_COORDINATOR,
        self::ROLE_SUPERVISOR,
        self::ROLE_ADMIN,
    ];

    public function hasRole(int ...$roleIds): bool
    {
        return in_array((int) $this->role_id, $roleIds, true);
    }

    /**
     * Supervisor scoping: students.supervisor_id is the source of truth for
     * who supervises whom (company_supervisors is only an eligibility roster).
     */
    public function supervises(?Student $student): bool
    {
        return $this->hasRole(self::ROLE_SUPERVISOR)
            && $student !== null
            && $student->supervisor_id !== null
            && (int) $student->supervisor_id === (int) $this->id;
    }

    /**
     * Delete every mobile API (Sanctum) token this user holds, so the app is
     * signed out on its next request (401). Called when staff deactivate the
     * account or change its role on the website.
     */
    public function revokeApiTokens(): void
    {
        $this->tokens()->delete();
    }

    public function canUseMobileApp(): bool
    {
        return in_array((int) $this->role_id, self::MOBILE_ROLE_IDS, true);
    }

    /**
     * What a Coordinator/Administrator may do, for the mobile app to build
     * its navigation (GET /api/v1/me `staff`). Mirrors the website's route
     * `role:` groups; they are UI hints only, every endpoint still enforces
     * its own rule. Null for non-staff roles.
     *
     * @return array<string, bool>|null
     */
    public function staffPermissions(): ?array
    {
        if (! $this->hasRole(self::ROLE_COORDINATOR, self::ROLE_ADMIN)) {
            return null;
        }

        $admin = $this->hasRole(self::ROLE_ADMIN);

        return [
            'can_manage_users' => true,
            'can_manage_companies' => true,
            'can_assign_internships' => true,
            'can_approve_attendance' => $admin,
            'can_edit_attendance' => $admin,
            'can_review_reports' => true,
            'can_write_evaluations' => $admin,
            'can_lock_evaluations' => $admin,
            'can_manage_criteria' => $admin,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

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

    public function supervisedCompanies()
    {
        return $this->belongsToMany(Company::class, 'company_supervisors')->withTimestamps();
    }

    /**
     * SIMS uses its own lightweight Notification model/table instead of
     * Laravel's polymorphic database notifications, so this intentionally
     * overrides Notifiable::notifications().
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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
