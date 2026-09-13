<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('doctors') || !Schema::hasTable('users')) {
            return;
        }
        if (!Schema::hasColumn('doctors', 'user_id') || !Schema::hasColumn('doctors', 'email')) {
            return;
        }

        // One-time ownership backfill: link guest profile rows (no user_id) to the
        // account that owns the same email, so portal history keeps working after
        // the email-fallback read path was removed for security.
        $unclaimed = DB::table('doctors')
            ->whereNull('user_id')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->select(['id', 'email'])
            ->get();

        foreach ($unclaimed as $doctor) {
            $email = strtolower(trim((string) $doctor->email));
            if ($email === '') continue;
            $userId = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->value('id');
            if ($userId) {
                DB::table('doctors')->where('id', $doctor->id)->whereNull('user_id')->update([
                    'user_id' => $userId,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        return;
    }
};
