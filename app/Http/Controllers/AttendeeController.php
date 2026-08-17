<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendeeController extends Controller
{
    private function attendeeNumber()
    {
        return 'ATT-' . strtoupper(base_convert(now()->timestamp, 10, 36)) . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function qrToken()
    {
        return bin2hex(random_bytes(32));
    }

    public function index(Request $request)
    {
        if (!$request->user()->hasPermission('attendees.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $eventId = (int)$request->query('eventId', 0);
        $limit = min(max((int) $request->query('limit', 250), 1), 1000);
        $offset = max((int) $request->query('offset', 0), 0);
        $search = trim((string) $request->query('search', ''));
        $includeMeta = filter_var($request->query('meta', false), FILTER_VALIDATE_BOOLEAN);
        if ($eventId && !$request->user()->hasEventScope($eventId)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $query = DB::table('attendees as a')
            ->join('events as e', 'e.id', '=', 'a.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'a.ticket_type_id')
            ->leftJoin('orders as o', 'o.id', '=', 'a.order_id')
            ->leftJoin('users as customer_user', 'customer_user.id', '=', 'o.customer_id')
            ->leftJoin('roles as customer_role', 'customer_role.id', '=', 'customer_user.role_id')
            ->select([
                'a.id', 'a.attendee_number', 'a.full_name', 'a.email', 'a.phone',
                'a.job_title', 'a.organization', 'a.qr_token', 'a.qr_status',
                'a.checked_in_at', 'a.certificate_issued_at', 'a.created_at',
                'a.event_id', 'a.ticket_type_id', 'e.title_en as event_title_en',
                'e.title_ar as event_title_ar', 'tt.name_en as ticket_name_en',
                'tt.name_ar as ticket_name_ar',
                DB::raw("COALESCE(customer_role.code, 'guest') as customer_role_code"),
                DB::raw("COALESCE(customer_role.name_en, 'Guest') as customer_role_name_en"),
                DB::raw("COALESCE(customer_role.name_ar, 'ضيف') as customer_role_name_ar")
            ]);

        if ($eventId) {
            $query->where('a.event_id', $eventId);
        }

        $request->user()->applyEventScope($query, 'a.event_id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('a.full_name', 'like', $like)
                    ->orWhere('a.email', 'like', $like)
                    ->orWhere('e.title_en', 'like', $like)
                    ->orWhere('e.title_ar', 'like', $like)
                    ->orWhere('tt.name_en', 'like', $like)
                    ->orWhere('tt.name_ar', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('a.created_at', 'desc')->offset($offset)->limit($limit)->get();

        if ($includeMeta) {
            return response()->json([
                'success' => true,
                'data' => $rows,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ]);
        }

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->hasPermission('attendees.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $attendee = DB::table('attendees as a')
            ->join('events as e', 'e.id', '=', 'a.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'a.ticket_type_id')
            ->leftJoin('generated_tickets as gt', 'gt.attendee_id', '=', 'a.id')
            ->leftJoin('certificates as c', 'c.attendee_id', '=', 'a.id')
            ->leftJoin('event_cards as ec', 'ec.attendee_id', '=', 'a.id')
            ->where('a.id', $id)
            ->select([
                'a.id', 'a.order_id', 'a.event_id', 'a.ticket_type_id', 'a.attendee_number',
                'a.full_name', 'a.email', 'a.phone', 'a.job_title', 'a.organization',
                'a.qr_token', 'a.qr_status', 'a.checked_in_at', 'a.certificate_issued_at',
                'a.created_at', 'e.title_en as event_title_en', 'e.title_ar as event_title_ar',
                'e.starts_at', 'e.ends_at', 'tt.name_en as ticket_name_en', 'tt.name_ar as ticket_name_ar',
                'gt.ticket_number', 'gt.pdf_url as ticket_pdf_url', 'c.certificate_number',
                'c.status as certificate_status', 'c.file_url as certificate_file_url',
                'ec.card_number', 'ec.file_url as card_file_url'
            ])
            ->first();

        if (!$attendee) {
            return response()->json(['success' => false, 'message' => 'Attendee not found'], 404);
        }

        if (!$request->user()->hasEventScope($attendee->event_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return response()->json(['success' => true, 'data' => $attendee]);
    }

    public function store(Request $request)
    {
        if (!$request->user()->hasPermission('attendees.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'orderId' => 'required|integer|min:1',
            'eventId' => 'required|integer|min:1',
            'ticketTypeId' => 'required|integer|min:1',
            'fullName' => 'required|string|min:2',
            'email' => 'required|email',
            'phone' => 'nullable|string',
            'jobTitle' => 'nullable|string',
            'organization' => 'nullable|string',
        ]);

        if (!$request->user()->hasEventScope($validated['eventId'])) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $attendeeNumber = $this->attendeeNumber();
        $qrToken = $this->qrToken();

        $id = DB::table('attendees')->insertGetId([
            'order_id' => $validated['orderId'],
            'event_id' => $validated['eventId'],
            'ticket_type_id' => $validated['ticketTypeId'],
            'attendee_number' => $attendeeNumber,
            'full_name' => $validated['fullName'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'job_title' => $validated['jobTitle'] ?? null,
            'organization' => $validated['organization'] ?? null,
            'qr_token' => $qrToken,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attendee created',
            'data' => array_merge($validated, ['id' => $id, 'attendeeNumber' => $attendeeNumber, 'qrToken' => $qrToken])
        ]);
    }

    public function checkin(Request $request)
    {
        if (!$request->user()->hasPermission('checkin.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $token = trim($request->input('qrToken', ''));
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'QR token is required'], 400);
        }

        if (!preg_match('/^[A-Fa-f0-9]{64}$/', $token)) {
            return response()->json(['success' => false, 'message' => 'Invalid QR code', 'details' => ['result' => 'invalid']], 404);
        }

        $eventId = (int)$request->input('eventId', 0);
        if ($eventId && !$request->user()->hasEventScope($eventId)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $attendee = DB::table('attendees')->where('qr_token', $token)->first(['id', 'event_id', 'full_name', 'qr_status', 'checked_in_at']);
        if (!$attendee) {
            return response()->json(['success' => false, 'message' => 'Invalid QR code', 'details' => ['result' => 'invalid']], 404);
        }

        if ($eventId && (int)$attendee->event_id !== $eventId) {
            DB::table('checkin_logs')->insert([
                'attendee_id' => $attendee->id,
                'event_id' => $attendee->event_id,
                'scanned_by_user_id' => $request->user()->id,
                'scan_result' => 'invalid',
                'scanned_at' => now(),
                'notes' => "wrong_event: scanner event {$eventId} does not match ticket event {$attendee->event_id}"
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ticket does not belong to this event',
                'details' => ['result' => 'wrong_event']
            ], 409);
        }

        if (!$eventId && !$request->user()->hasEventScope($attendee->event_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($attendee->checked_in_at || $attendee->qr_status === 'used') {
            DB::table('checkin_logs')->insert([
                'attendee_id' => $attendee->id,
                'event_id' => $attendee->event_id,
                'scanned_by_user_id' => $request->user()->id,
                'scan_result' => 'duplicate',
                'scanned_at' => now(),
                'notes' => 'Already checked in'
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Attendee already checked in',
                'details' => ['result' => 'duplicate', 'attendee' => $attendee]
            ], 409);
        }

        if ($attendee->qr_status !== 'active') {
            DB::table('checkin_logs')->insert([
                'attendee_id' => $attendee->id,
                'event_id' => $attendee->event_id,
                'scanned_by_user_id' => $request->user()->id,
                'scan_result' => 'revoked',
                'scanned_at' => now(),
                'notes' => 'QR is not active'
            ]);
            return response()->json([
                'success' => false,
                'message' => 'QR code is not active',
                'details' => ['result' => 'revoked']
            ], 409);
        }

        $checkedIn = DB::transaction(function () use ($attendee, $request) {
            $updated = DB::table('attendees')
                ->where('id', $attendee->id)
                ->whereNull('checked_in_at')
                ->where('qr_status', 'active')
                ->update([
                'checked_in_at' => now(),
                'qr_status' => 'used'
            ]);

            if (!$updated) {
                DB::table('checkin_logs')->insert([
                    'attendee_id' => $attendee->id,
                    'event_id' => $attendee->event_id,
                    'scanned_by_user_id' => $request->user()->id,
                    'scan_result' => 'duplicate',
                    'scanned_at' => now(),
                    'notes' => 'Already checked in'
                ]);
                return null;
            }

            DB::table('checkin_logs')->insert([
                'attendee_id' => $attendee->id,
                'event_id' => $attendee->event_id,
                'scanned_by_user_id' => $request->user()->id,
                'scanned_at' => now(),
                'scan_result' => 'accepted'
            ]);

            return DB::table('attendees')->where('id', $attendee->id)->first(['id', 'attendee_number', 'full_name', 'email', 'checked_in_at']);
        });

        if (!$checkedIn) {
            $duplicate = DB::table('attendees')->where('id', $attendee->id)->first(['id', 'event_id', 'full_name', 'qr_status', 'checked_in_at']);
            return response()->json([
                'success' => false,
                'message' => 'Attendee already checked in',
                'details' => ['result' => 'duplicate', 'attendee' => $duplicate]
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Check-in accepted',
            'data' => $checkedIn
        ]);
    }

    public function updateQrStatus(Request $request, $id)
    {
        if (!$request->user()->hasPermission('attendees.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $status = in_array($request->input('status'), ['active', 'revoked', 'used']) ? $request->input('status') : null;
        if (!$status) {
            return response()->json(['success' => false, 'message' => 'Invalid QR status'], 400);
        }

        $attendee = DB::table('attendees')->where('id', $id)->first(['id', 'event_id']);
        if (!$attendee) {
            return response()->json(['success' => false, 'message' => 'Attendee not found'], 404);
        }

        if (!$request->user()->hasEventScope($attendee->event_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        DB::table('attendees')->where('id', $id)->update(['qr_status' => $status]);

        return response()->json([
            'success' => true,
            'message' => 'Attendee QR status updated',
            'data' => ['id' => (int)$id, 'status' => $status]
        ]);
    }
}
