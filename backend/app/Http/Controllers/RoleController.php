<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class RoleController extends Controller
{
    protected $permissionCatalog = [
        ['key' => 'dashboard.view', 'labelEn' => 'View admin dashboard', 'labelAr' => 'عرض لوحة التحكم'],
        ['key' => 'certificates.view', 'labelEn' => 'View certificates', 'labelAr' => 'عرض الشهادات'],
        ['key' => 'reviews.view', 'labelEn' => 'View reviews', 'labelAr' => 'عرض التقييمات'],
        ['key' => 'kiosk.use', 'labelEn' => 'Use kiosk search', 'labelAr' => 'استخدام بحث الكشك'],
        ['key' => 'registrations.create_manual', 'labelEn' => 'Create registrations manually', 'labelAr' => 'إنشاء تسجيلات يدويًا'],
        ['key' => 'attendees.manage', 'labelEn' => 'Manage attendees', 'labelAr' => 'إدارة الحضور'],
        ['key' => 'checkin.manage', 'labelEn' => 'Manage check-in / Badges', 'labelAr' => 'إدارة تسجيل الدخول / الشارات'],
        ['key' => 'certificates.manage', 'labelEn' => 'Manage certificates', 'labelAr' => 'إدارة الشهادات'],
        ['key' => 'contact_inquiries.manage', 'labelEn' => 'Manage contact inquiries', 'labelAr' => 'إدارة استفسارات الاتصال'],
        ['key' => 'events.manage', 'labelEn' => 'Manage events', 'labelAr' => 'إدارة الفعاليات'],
        ['key' => 'theme_identity.manage', 'labelEn' => 'Manage theme & identity', 'labelAr' => 'إدارة المظهر والهوية'],
        ['key' => 'website_content.manage', 'labelEn' => 'Manage website content', 'labelAr' => 'إدارة محتوى الموقع'],
        ['key' => 'settings.manage', 'labelEn' => 'Manage platform settings', 'labelAr' => 'إدارة إعدادات المنصة'],
        ['key' => 'registrations.manage', 'labelEn' => 'Manage registrations (lists/exports)', 'labelAr' => 'إدارة التسجيلات'],
        ['key' => 'payments.verify', 'labelEn' => 'Verify offline payments', 'labelAr' => 'التحقق من المدفوعات'],
        ['key' => 'reports.view', 'labelEn' => 'View reports', 'labelAr' => 'عرض التقارير'],
        ['key' => 'reviews.manage', 'labelEn' => 'Manage reviews', 'labelAr' => 'إدارة التقييمات'],
        ['key' => 'tickets.manage', 'labelEn' => 'Manage tickets', 'labelAr' => 'إدارة التذاكر'],
        ['key' => 'pricing.manage', 'labelEn' => 'Manage pricing / discounts', 'labelAr' => 'إدارة الأسعار / الخصومات'],
        ['key' => 'users.manage', 'labelEn' => 'Manage users', 'labelAr' => 'إدارة المستخدمين'],
        ['key' => 'roles.manage', 'labelEn' => 'Manage roles & permissions', 'labelAr' => 'إدارة الأدوار والصلاحيات'],
        ['key' => 'profile.manage', 'labelEn' => 'Own profile', 'labelAr' => 'إدارة الحساب الشخصي'],
    ];

    public function index()
    {
        $roles = DB::table('roles')
            ->orderByRaw("FIELD(code, 'admin', 'organizer', 'back_office', 'employee', 'chairman', 'speaker', 'doctor', 'customer')")
            ->orderBy('id')
            ->get();

        $permissions = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->select('roles.code as role_code', 'role_permissions.permission_key', 'role_permissions.allowed')
            ->get();

        $byRole = [];
        foreach ($roles as $role) {
            $mapped = [
                'id' => $role->id,
                'code' => $role->code,
                'nameEn' => $role->name_en,
                'nameAr' => $role->name_ar,
                'permissions' => []
            ];
            foreach ($this->permissionCatalog as $item) {
                $mapped['permissions'][] = array_merge($item, ['allowed' => false]);
            }
            $byRole[$role->code] = $mapped;
        }

        foreach ($permissions as $permission) {
            if (!isset($byRole[$permission->role_code])) continue;
            foreach ($byRole[$permission->role_code]['permissions'] as &$item) {
                if ($item['key'] === $permission->permission_key) {
                    $item['allowed'] = (bool) $permission->allowed;
                    break;
                }
            }
        }

        return ApiResponse::ok([
            'catalog' => $this->permissionCatalog,
            'roles' => array_values($byRole)
        ]);
    }

    public function updatePermissions(Request $request, $roleCode)
    {
        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*.key' => 'required|string|min:2',
            'permissions.*.allowed' => 'required|boolean',
        ]);

        $role = Role::where('code', $roleCode)->first();
        if (!$role) {
            return ApiResponse::fail('Role not found', 404);
        }

        $validKeys = array_column($this->permissionCatalog, 'key');
        foreach ($validated['permissions'] as $p) {
            if (!in_array($p['key'], $validKeys)) {
                return ApiResponse::fail("Unknown permission: {$p['key']}", 400);
            }
        }

        if ($role->code === 'admin') {
            $critical = ['users.manage', 'roles.manage'];
            foreach ($validated['permissions'] as $p) {
                if (in_array($p['key'], $critical) && !$p['allowed']) {
                    return ApiResponse::fail("Admin role must keep {$p['key']}", 400);
                }
            }
        }

        DB::transaction(function () use ($role, $validated) {
            foreach ($validated['permissions'] as $permission) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_key' => $permission['key']],
                    ['allowed' => $permission['allowed'] ? 1 : 0]
                );
            }
        });

        // Fetch saved
        $saved = DB::table('role_permissions')->where('role_id', $role->id)->get();
        $permissionsList = [];
        foreach ($this->permissionCatalog as $item) {
            $allowed = false;
            foreach ($saved as $s) {
                if ($s->permission_key === $item['key']) {
                    $allowed = (bool) $s->allowed;
                    break;
                }
            }
            $permissionsList[] = array_merge($item, ['allowed' => $allowed]);
        }

        $mapped = [
            'id' => $role->id,
            'code' => $role->code,
            'nameEn' => $role->name_en,
            'nameAr' => $role->name_ar,
            'permissions' => $permissionsList
        ];

        $user = Auth::guard('api')->user();
        DB::table('audit_logs')->insert([
            'user_id' => $user ? $user->id : null,
            'action' => 'users.role_permissions_update',
            'entity_type' => 'role',
            'entity_id' => (string) $role->id,
            'metadata_json' => json_encode(['roleCode' => $role->code, 'permissions' => $validated['permissions']]),
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('user-agent'),
        ]);

        return ApiResponse::ok(['role' => $mapped], 'Permissions updated');
    }
}
