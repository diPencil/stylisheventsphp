<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['id' => 1, 'code' => 'admin', 'name_en' => 'Admin', 'name_ar' => 'مدير النظام'],
            ['id' => 2, 'code' => 'organizer', 'name_en' => 'Organizer', 'name_ar' => 'منظم'],
            ['id' => 3, 'code' => 'employee', 'name_en' => 'Employee', 'name_ar' => 'موظف'],
            ['id' => 4, 'code' => 'customer', 'name_en' => 'Customer', 'name_ar' => 'عميل'],
            ['id' => 5, 'code' => 'doctor', 'name_en' => 'Doctor', 'name_ar' => 'طبيب'],
            ['id' => 6, 'code' => 'back_office', 'name_en' => 'Back Office', 'name_ar' => 'الدعم التشغيلي'],
            ['id' => 750, 'code' => 'chairman', 'name_en' => 'Chairman', 'name_ar' => 'رئيس الجلسة'],
            ['id' => 751, 'code' => 'speaker', 'name_en' => 'Speaker', 'name_ar' => 'متحدث'],
        ];

        foreach ($roles as $role) {
            $existing = DB::table('roles')->where('code', $role['code'])->first();
            if ($existing) {
                // Update canonical display names only; do not change id or other custom fields.
                DB::table('roles')->where('code', $role['code'])->update([
                    'name_en' => $role['name_en'],
                    'name_ar' => $role['name_ar'],
                ]);
            } else {
                // Insert with the authoritative ID when possible to preserve stable IDs.
                DB::table('roles')->insert([
                    'id' => $role['id'],
                    'code' => $role['code'],
                    'name_en' => $role['name_en'],
                    'name_ar' => $role['name_ar'],
                    // let DB set created_at via default timestamp
                ]);
            }
        }

        // Sync a minimal set of default participant permissions (authoritative source: routes/console.php)
        $participantRoles = ['doctor', 'chairman', 'speaker'];
        foreach ($participantRoles as $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');
            if ($roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_key' => 'profile.manage'],
                    ['allowed' => 1]
                );
            }
        }

        // Ensure admin has full platform permissions (permission catalog defined in RoleController).
        $adminId = DB::table('roles')->where('code', 'admin')->value('id');
        if ($adminId) {
            $permissionKeys = [
                'dashboard.view','certificates.view','reviews.view','kiosk.use','registrations.create_manual',
                'attendees.manage','checkin.manage','certificates.manage','contact_inquiries.manage','events.manage',
                'theme_identity.manage','website_content.manage','settings.manage','registrations.manage','payments.verify',
                'reports.view','reviews.manage','tickets.manage','pricing.manage','users.manage','roles.manage','profile.manage'
            ];

            foreach ($permissionKeys as $key) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $adminId, 'permission_key' => $key],
                    ['allowed' => 1]
                );
            }
        }
    }
}
