<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('roles:sync-defaults', function () {
    $roles = [
        ['code' => 'chairman', 'name_en' => 'Chairman', 'name_ar' => 'رئيس الجلسة'],
        ['code' => 'speaker', 'name_en' => 'Speaker', 'name_ar' => 'متحدث'],
    ];

    foreach ($roles as $role) {
        DB::table('roles')->updateOrInsert(
            ['code' => $role['code']],
            [
                'name_en' => $role['name_en'],
                'name_ar' => $role['name_ar'],
            ]
        );

        $roleId = DB::table('roles')->where('code', $role['code'])->value('id');
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $roleId, 'permission_key' => 'profile.manage'],
            ['allowed' => 1]
        );
    }

    $this->info('Default participant roles synced.');
})->purpose('Create or update default participant roles without migrations');
