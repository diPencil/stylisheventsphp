<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = ['id'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * Get the password for the user.
     */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /**
     * Get the password for the user.
     * Compatibility with Laravel 11's method naming.
     */
    public function getAuthPasswordName()
    {
        return 'password_hash';
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function getRoleCodeAttribute()
    {
        return $this->role()->value('code');
    }

    public function hasPermission($permissionKey)
    {
        if (!$this->role_id) return false;

        $roleCode = $this->role()->value('code');
        if ($roleCode === 'admin') return true;

        return \Illuminate\Support\Facades\DB::table('role_permissions')
            ->where('role_id', $this->role_id)
            ->where('permission_key', $permissionKey)
            ->where('allowed', 1)
            ->exists();
    }

    public function isGlobalStaff()
    {
        $role = $this->role()->first();
        return $role && in_array($role->code, ['admin', 'back_office']);
    }

    public function hasEventScope($eventId)
    {
        if (!$eventId) return false;
        if ($this->isGlobalStaff()) return true;

        $roleCode = $this->role()->first()->code ?? '';
        if ($roleCode === 'organizer') {
            return \Illuminate\Support\Facades\DB::table('events')
                ->where('id', $eventId)
                ->where('organizer_id', $this->id)
                ->exists();
        }
        if ($roleCode === 'employee') {
            return \Illuminate\Support\Facades\DB::table('event_staff_assignments')
                ->where('event_id', $eventId)
                ->where('user_id', $this->id)
                ->where('is_active', 1)
                ->exists();
        }
        return false;
    }

    public function applyEventScope($query, $eventColumn = 'e.id')
    {
        if ($this->isGlobalStaff()) {
            return $query;
        }

        $roleCode = $this->role()->first()->code ?? '';

        if ($roleCode === 'organizer') {
            $eventTableAlias = explode('.', $eventColumn)[0];
            return $query->where($eventTableAlias . '.organizer_id', $this->id);
        }

        if ($roleCode === 'employee') {
            return $query->whereExists(function ($q) use ($eventColumn) {
                $q->select(\Illuminate\Support\Facades\DB::raw(1))
                  ->from('event_staff_assignments')
                  ->whereColumn('event_staff_assignments.event_id', $eventColumn)
                  ->where('event_staff_assignments.user_id', $this->id)
                  ->where('event_staff_assignments.is_active', 1);
            });
        }

        return $query->whereRaw('0 = 1');
    }
}
