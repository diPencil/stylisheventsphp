<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendee_daily_checkins')) {
            return;
        }

        Schema::create('attendee_daily_checkins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendee_id')->index();
            $table->unsignedBigInteger('event_id')->index();
            $table->date('checkin_date')->index();
            $table->timestamp('first_checked_in_at')->nullable();
            $table->timestamp('last_checked_in_at')->nullable();
            $table->unsignedBigInteger('first_scanned_by_user_id')->nullable()->index();
            $table->unsignedBigInteger('last_scanned_by_user_id')->nullable()->index();
            $table->string('first_source')->nullable();
            $table->string('last_source')->nullable();
            $table->unsignedInteger('checkin_count')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['attendee_id', 'event_id', 'checkin_date'], 'attendee_daily_unique');
        });

        $this->backfillFromCheckinLogs();
    }

    public function down(): void
    {
        Schema::dropIfExists('attendee_daily_checkins');
    }

    private function backfillFromCheckinLogs(): void
    {
        if (!Schema::hasTable('checkin_logs')) {
            return;
        }

        DB::table('checkin_logs')
            ->where('scan_result', 'accepted')
            ->whereNotNull('attendee_id')
            ->whereNotNull('event_id')
            ->whereNotNull('scanned_at')
            ->select([
                'attendee_id',
                'event_id',
                DB::raw('DATE(scanned_at) as checkin_date'),
                DB::raw('MIN(scanned_at) as first_checked_in_at'),
                DB::raw('MAX(scanned_at) as last_checked_in_at'),
                DB::raw('MIN(scanned_by_user_id) as first_scanned_by_user_id'),
                DB::raw('MAX(scanned_by_user_id) as last_scanned_by_user_id'),
                DB::raw("SUM(CASE WHEN notes LIKE '%source:scan%' THEN 1 ELSE 0 END) as scan_count"),
                DB::raw("SUM(CASE WHEN notes LIKE '%source:manual%' THEN 1 ELSE 0 END) as manual_count"),
                DB::raw('COUNT(*) as checkin_count'),
            ])
            ->groupBy('attendee_id', 'event_id', DB::raw('DATE(scanned_at)'))
            ->orderBy('event_id')
            ->orderBy('attendee_id')
            ->get()
            ->chunk(500)
            ->each(function ($rows): void {
                $now = now();
                $payload = $rows->map(function ($row) use ($now): array {
                    $source = ((int) $row->scan_count > 0) ? 'scan' : (((int) $row->manual_count > 0) ? 'manual' : 'unknown');

                    return [
                        'attendee_id' => $row->attendee_id,
                        'event_id' => $row->event_id,
                        'checkin_date' => $row->checkin_date,
                        'first_checked_in_at' => $row->first_checked_in_at,
                        'last_checked_in_at' => $row->last_checked_in_at,
                        'first_scanned_by_user_id' => $row->first_scanned_by_user_id,
                        'last_scanned_by_user_id' => $row->last_scanned_by_user_id,
                        'first_source' => $source,
                        'last_source' => $source,
                        'checkin_count' => $row->checkin_count,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all();

                if ($payload !== []) {
                    DB::table('attendee_daily_checkins')->insertOrIgnore($payload);
                }
            });
    }
};
