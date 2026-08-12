<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
}
