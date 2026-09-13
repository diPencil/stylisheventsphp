<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    private function requireEventScope($user, $eventId)
    {
        if (!$eventId) return true;
        if ($user && $user->role_code === 'employee') {
            $hasAccess = DB::table('event_staff_assignments')
                ->where('user_id', $user->id)
                ->where('event_id', $eventId)
                ->exists();
            return $hasAccess;
        }
        return true;
    }

    private function applyEventScope($query, $user, $columnName)
    {
        if ($user && $user->role_code === 'employee') {
            $query->whereIn($columnName, function ($q) use ($user) {
                $q->select('event_id')
                  ->from('event_staff_assignments')
                  ->where('user_id', $user->id);
            });
        }
    }

    private function reportDateRange(Request $request): array
    {
        $validated = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        $from = $validated['date'] ?? ($validated['from'] ?? null);
        $to = $validated['date'] ?? ($validated['to'] ?? null);

        return [
            $from ? Carbon::parse($from)->startOfDay() : null,
            $to ? Carbon::parse($to)->endOfDay() : null,
            $from,
            $to,
        ];
    }

    public function summary(Request $request)
    {
        $eventId = (int) $request->query('eventId', 0);
        $user = $request->user();

        if ($eventId !== 0 && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $regsQuery = DB::table('registrations as r')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->select('r.registration_status as status', DB::raw('COUNT(*) as count'))
            ->groupBy('r.registration_status');

        $paymentsQuery = DB::table('registrations as r')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->select('r.payment_status as status', DB::raw('COUNT(*) as count'))
            ->groupBy('r.payment_status');

        $revenueQuery = DB::table('orders as o')
            ->join('events as e', 'e.id', '=', 'o.event_id')
            ->select('o.currency', DB::raw('COALESCE(SUM(o.grand_total), 0) as total'), DB::raw('COUNT(*) as paid_orders'))
            ->where('o.status', 'paid')
            ->groupBy('o.currency');

        $certsQuery = DB::table('certificates as c')
            ->join('attendees as a', 'a.id', '=', 'c.attendee_id')
            ->join('events as e', 'e.id', '=', 'a.event_id')
            ->select('c.status', DB::raw('COUNT(*) as count'))
            ->groupBy('c.status');

        if ($eventId !== 0) {
            $regsQuery->where('e.id', $eventId);
            $paymentsQuery->where('e.id', $eventId);
            $revenueQuery->where('e.id', $eventId);
            $certsQuery->where('e.id', $eventId);
        }

        $this->applyEventScope($regsQuery, $user, 'e.id');
        $this->applyEventScope($paymentsQuery, $user, 'e.id');
        $this->applyEventScope($revenueQuery, $user, 'e.id');
        $this->applyEventScope($certsQuery, $user, 'e.id');

        return response()->json([
            'status' => 'success',
            'data' => [
                'registrations' => $regsQuery->get(),
                'payments' => $paymentsQuery->get(),
                'revenue' => $revenueQuery->get(),
                'certificates' => $certsQuery->get(),
            ]
        ]);
    }

    public function registrations(Request $request)
    {
        $eventId = (int) $request->query('eventId', 0);
        $user = $request->user();

        if ($eventId !== 0 && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $query = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'r.ticket_type_id')
            ->leftJoin('users as customer_user', 'customer_user.id', '=', 'd.user_id')
            ->leftJoin('roles as customer_role', 'customer_role.id', '=', 'customer_user.role_id')
            ->select(
                'r.registration_number',
                'r.source',
                'r.registration_status',
                'r.payment_status',
                'r.selected_currency',
                'r.selected_price',
                'r.created_at',
                'r.event_id',
                'd.full_name AS doctor_name',
                'd.email AS doctor_email',
                'd.mobile AS doctor_mobile',
                'd.country_name',
                'd.nationality',
                'd.specialty',
                'e.title_en AS event_title_en',
                'tt.name_en AS ticket_name_en',
                DB::raw("COALESCE(customer_role.code, 'guest') AS customer_role_code"),
                DB::raw("COALESCE(customer_role.name_en, 'Guest') AS customer_role_name_en"),
                DB::raw("COALESCE(customer_role.name_ar, 'ضيف') AS customer_role_name_ar")
            )
            ->orderBy('r.created_at', 'desc')
            ->limit(1000);

        if ($eventId !== 0) {
            $query->where('e.id', $eventId);
        }

        $this->applyEventScope($query, $user, 'e.id');

        return response()->json([
            'status' => 'success',
            'data' => $query->get()
        ]);
    }

    public function nationalities(Request $request)
    {
        $eventId = (int) $request->query('eventId', 0);
        $user = $request->user();

        if ($eventId !== 0 && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $query = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->select('d.nationality', 'd.country_name', DB::raw('COUNT(*) as registrations'))
            ->groupBy('d.nationality', 'd.country_name')
            ->orderBy('registrations', 'desc');

        if ($eventId !== 0) {
            $query->where('e.id', $eventId);
        }

        $this->applyEventScope($query, $user, 'e.id');

        return response()->json([
            'status' => 'success',
            'data' => $query->get()
        ]);
    }

    public function specialties(Request $request)
    {
        $eventId = (int) $request->query('eventId', 0);
        $user = $request->user();

        if ($eventId !== 0 && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $query = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->select('d.specialty', DB::raw('COUNT(*) as registrations'))
            ->groupBy('d.specialty')
            ->orderBy('registrations', 'desc');

        if ($eventId !== 0) {
            $query->where('e.id', $eventId);
        }

        $this->applyEventScope($query, $user, 'e.id');

        return response()->json([
            'status' => 'success',
            'data' => $query->get()
        ]);
    }

    public function ticketPerformance(Request $request)
    {
        $eventId = (int) $request->query('eventId', 0);
        $user = $request->user();

        if ($eventId !== 0 && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $query = DB::table('ticket_types as tt')
            ->join('events as e', 'e.id', '=', 'tt.event_id')
            ->leftJoin('registrations as r', 'r.ticket_type_id', '=', 'tt.id')
            ->select(
                'e.title_en AS event_title_en',
                'tt.name_en AS ticket_name_en',
                'tt.quota',
                DB::raw('COUNT(r.id) AS registrations'),
                DB::raw("SUM(CASE WHEN r.payment_status = 'approved' THEN 1 ELSE 0 END) AS approved"),
                DB::raw("SUM(CASE WHEN r.payment_status = 'pending' THEN 1 ELSE 0 END) AS pending"),
                DB::raw("SUM(CASE WHEN r.payment_status = 'rejected' THEN 1 ELSE 0 END) AS rejected")
            )
            ->groupBy('e.id', 'tt.id')
            ->orderBy('registrations', 'desc');

        if ($eventId !== 0) {
            $query->where('e.id', $eventId);
        }

        // The scope applies to e.id in this query
        if ($user && $user->role_code === 'employee') {
            $query->whereIn('e.id', function ($q) use ($user) {
                $q->select('event_id')
                  ->from('event_staff_assignments')
                  ->where('user_id', $user->id);
            });
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->get()
        ]);
    }

    public function attendance(Request $request)
    {
        $eventId = (int) $request->query('eventId', 0);
        $user = $request->user();
        [$fromDate, $toDate, $fromLabel, $toLabel] = $this->reportDateRange($request);

        if ($eventId !== 0 && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $logStats = DB::table('checkin_logs')
            ->select(
                'event_id',
                DB::raw("COUNT(*) as total_scans"),
                DB::raw("SUM(CASE WHEN scan_result = 'accepted' THEN 1 ELSE 0 END) as accepted_scans"),
                DB::raw("SUM(CASE WHEN scan_result = 'duplicate' THEN 1 ELSE 0 END) as duplicate_scans"),
                DB::raw("SUM(CASE WHEN scan_result = 'invalid' THEN 1 ELSE 0 END) as invalid_scans"),
                DB::raw("SUM(CASE WHEN scan_result = 'revoked' THEN 1 ELSE 0 END) as revoked_scans"),
                DB::raw("SUM(CASE WHEN LOWER(COALESCE(notes, '')) LIKE '%source:manual%' THEN 1 ELSE 0 END) as manual_scans"),
                DB::raw("SUM(CASE WHEN LOWER(COALESCE(notes, '')) LIKE '%source:scan%' THEN 1 ELSE 0 END) as qr_scans"),
                DB::raw("COUNT(DISTINCT CASE WHEN scan_result = 'accepted' THEN attendee_id END) as range_checked_in"),
                DB::raw("MIN(CASE WHEN scan_result = 'accepted' THEN scanned_at END) as first_checkin_at"),
                DB::raw("MAX(CASE WHEN scan_result = 'accepted' THEN scanned_at END) as last_checkin_at")
            )
            ->groupBy('event_id');

        if ($fromDate) {
            $logStats->where('scanned_at', '>=', $fromDate);
        }

        if ($toDate) {
            $logStats->where('scanned_at', '<=', $toDate);
        }

        $query = DB::table('events as e')
            ->leftJoin('attendees as a', 'a.event_id', '=', 'e.id')
            ->leftJoinSub($logStats, 'ls', function ($join) {
                $join->on('ls.event_id', '=', 'e.id');
            })
            ->select(
                'e.id as event_id',
                'e.title_en as event_title_en',
                'e.title_ar as event_title_ar',
                DB::raw('COUNT(a.id) as total_attendees'),
                DB::raw("SUM(CASE WHEN a.checked_in_at IS NOT NULL OR a.qr_status = 'used' THEN 1 ELSE 0 END) as total_checked_in"),
                DB::raw('COALESCE(ls.range_checked_in, 0) as range_checked_in'),
                DB::raw('COALESCE(ls.total_scans, 0) as total_scans'),
                DB::raw('COALESCE(ls.accepted_scans, 0) as accepted_scans'),
                DB::raw('COALESCE(ls.duplicate_scans, 0) as duplicate_scans'),
                DB::raw('COALESCE(ls.invalid_scans, 0) as invalid_scans'),
                DB::raw('COALESCE(ls.revoked_scans, 0) as revoked_scans'),
                DB::raw('COALESCE(ls.manual_scans, 0) as manual_scans'),
                DB::raw('COALESCE(ls.qr_scans, 0) as qr_scans'),
                DB::raw('ls.first_checkin_at as first_checkin_at'),
                DB::raw('ls.last_checkin_at as last_checkin_at')
            )
            ->groupBy(
                'e.id',
                'e.title_en',
                'e.title_ar',
                'ls.range_checked_in',
                'ls.total_scans',
                'ls.accepted_scans',
                'ls.duplicate_scans',
                'ls.invalid_scans',
                'ls.revoked_scans',
                'ls.manual_scans',
                'ls.qr_scans',
                'ls.first_checkin_at',
                'ls.last_checkin_at'
            )
            ->orderBy('e.starts_at', 'desc');

        if ($eventId !== 0) {
            $query->where('e.id', $eventId);
        }

        $this->applyEventScope($query, $user, 'e.id');

        return response()->json([
            'status' => 'success',
            'data' => $query->get()->map(function ($row) use ($fromLabel, $toLabel) {
                $row->date_from = $fromLabel;
                $row->date_to = $toLabel;
                return $row;
            }),
        ]);
    }
}
