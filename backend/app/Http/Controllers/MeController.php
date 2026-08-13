<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MeController extends Controller
{
    private function hasPaymentMethodColumn()
    {
        return Schema::hasColumn('registrations', 'payment_method');
    }

    private function customerRegistrations(Request $request, array $options = [])
    {
        $page = (int) ($options['page'] ?? 1);
        $perPage = (int) ($options['perPage'] ?? 10);
        $search = $options['search'] ?? '';
        $status = $options['status'] ?? '';
        $period = $options['period'] ?? 'all';

        if ($page < 1) $page = 1;
        if ($perPage < 1 || $perPage > 50) $perPage = 10;

        $userId = $request->user()->id;

        $query = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'r.ticket_type_id')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->leftJoin('certificates as c', 'c.attendee_id', '=', 'a.id')
            ->where('d.user_id', $userId);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where('r.registration_number', 'LIKE', $like)
                  ->orWhere('e.title_en', 'LIKE', $like)
                  ->orWhere('e.title_ar', 'LIKE', $like)
                  ->orWhere('tt.name_en', 'LIKE', $like)
                  ->orWhere('tt.name_ar', 'LIKE', $like);
            });
        }

        if ($status) {
            $query->where(function ($q) use ($status) {
                $q->where('r.registration_status', $status)
                  ->orWhere('r.payment_status', $status)
                  ->orWhere('o.status', $status);
            });
        }

        if (isset($options['registrationStatuses']) && is_array($options['registrationStatuses']) && count($options['registrationStatuses'])) {
            $query->whereIn('r.registration_status', $options['registrationStatuses']);
        }

        if ($period === 'upcoming') {
            $query->whereRaw('e.starts_at >= NOW()');
        } elseif ($period === 'past') {
            $query->whereRaw('e.ends_at < NOW()');
        }

        if (!empty($options['requireTicket'])) {
            $query->whereNotNull('gt.id');
        }
        if (!empty($options['requireCertificate'])) {
            $query->whereNotNull('c.id');
        }

        $total = $query->count();

        $rows = $query->select([
                'r.id',
                'r.registration_number',
                'r.registration_status',
                'r.payment_status',
                'r.selected_currency',
                'r.selected_price',
                $this->hasPaymentMethodColumn() ? 'r.payment_method' : DB::raw('NULL as payment_method'),
                'r.created_at',
                'r.updated_at',
                'o.order_number',
                'o.status as order_status',
                'd.full_name',
                'd.email',
                'd.mobile',
                'd.specialty',
                'e.id as event_id',
                'e.slug as event_slug',
                'e.title_en as event_title_en',
                'e.title_ar as event_title_ar',
                'e.summary_en as event_summary_en',
                'e.summary_ar as event_summary_ar',
                'e.description_en as event_description_en',
                'e.description_ar as event_description_ar',
                'e.type as event_type',
                'e.status as event_status',
                'e.starts_at',
                'e.ends_at',
                'e.timezone',
                'e.cover_image_url',
                'e.banner_image_url',
                'e.gallery_json',
                'e.google_maps_url',
                'e.registration_ends_at',
                'v.name_en as venue_name_en',
                'v.name_ar as venue_name_ar',
                'v.city_en',
                'v.city_ar',
                'v.address_en',
                'v.address_ar',
                'tt.name_en as ticket_name_en',
                'tt.name_ar as ticket_name_ar',
                'tt.description_en as ticket_description_en',
                'tt.description_ar as ticket_description_ar',
                'gt.id as ticket_id',
                'gt.ticket_number',
                'gt.pdf_url as ticket_pdf_url',
                'a.qr_status',
                'a.checked_in_at',
                'c.id as certificate_id',
                'c.certificate_number',
                'c.status as certificate_status',
                'c.file_url as certificate_file_url',
                'c.issued_at as certificate_issued_at'
            ])
            ->orderBy('r.created_at', 'desc')
            ->orderBy('r.id', 'desc')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage
        ];
    }

    public function dashboard(Request $request)
    {
        $userId = $request->user()->id;

        $recent = $this->customerRegistrations($request, ['page' => 1, 'perPage' => 5, 'period' => 'all']);
        $upcoming = $this->customerRegistrations($request, ['page' => 1, 'perPage' => 1, 'period' => 'upcoming', 'status' => 'approved']);
        $pendingUpcoming = $this->customerRegistrations($request, [
            'page' => 1,
            'perPage' => 1,
            'period' => 'upcoming',
            'registrationStatuses' => ['pending_verification', 'pending_payment', 'pending_review', 'pending'],
        ]);

        $counts = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->leftJoin('certificates as c', 'c.attendee_id', '=', 'a.id')
            ->where('d.user_id', $userId)
            ->selectRaw("
                COUNT(*) as total_registrations,
                SUM(CASE WHEN e.starts_at >= NOW() AND r.registration_status IN ('approved','pending_payment','pending_verification','pending_review') THEN 1 ELSE 0 END) as upcoming_registrations,
                SUM(CASE WHEN gt.id IS NOT NULL AND COALESCE(a.qr_status, 'active') = 'active' THEN 1 ELSE 0 END) as active_tickets,
                SUM(CASE WHEN c.id IS NOT NULL AND c.status = 'issued' THEN 1 ELSE 0 END) as available_certificates
            ")
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'user' => $request->user(),
                'summary' => [
                    'totalRegistrations' => (int) ($counts->total_registrations ?? 0),
                    'upcomingRegistrations' => (int) ($counts->upcoming_registrations ?? 0),
                    'activeTickets' => (int) ($counts->active_tickets ?? 0),
                    'availableCertificates' => (int) ($counts->available_certificates ?? 0),
                    'unreadNotifications' => 0,
                ],
                'nextEvent' => $upcoming['rows'][0] ?? null,
                'pendingUpcomingRegistration' => !empty($upcoming['rows'][0]) ? null : ($pendingUpcoming['rows'][0] ?? null),
                'recentRegistrations' => $recent['rows'],
                'notifications' => [],
            ],
        ]);
    }

    public function registrations(Request $request)
    {
        $result = $this->customerRegistrations($request, $request->all());
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'data' => $result['rows'],
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'perPage' => $result['perPage']
                ],
            ],
        ]);
    }

    public function showRegistration(Request $request, $id)
    {
        $id = (int)$id;
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'Invalid registration id'], 400);
        }

        $userId = $request->user()->id;

        $row = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'r.ticket_type_id')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->leftJoin('certificates as c', 'c.attendee_id', '=', 'a.id')
            ->where('r.id', $id)
            ->where('d.user_id', $userId)
            ->select([
                'r.id',
                'r.registration_number',
                'r.registration_status',
                'r.payment_status',
                'r.selected_currency',
                'r.selected_price',
                'r.payment_reference',
                $this->hasPaymentMethodColumn() ? 'r.payment_method' : DB::raw('NULL as payment_method'),
                'r.payment_proof_url',
                'r.created_at',
                'r.updated_at',
                'o.order_number',
                'o.status as order_status',
                'd.full_name',
                'd.email',
                'd.mobile',
                'd.city',
                'd.specialty',
                'd.nationality',
                'e.id as event_id',
                'e.slug as event_slug',
                'e.title_en as event_title_en',
                'e.title_ar as event_title_ar',
                'e.summary_en as event_summary_en',
                'e.summary_ar as event_summary_ar',
                'e.description_en as event_description_en',
                'e.description_ar as event_description_ar',
                'e.type as event_type',
                'e.status as event_status',
                'e.starts_at',
                'e.ends_at',
                'e.timezone',
                'e.cover_image_url',
                'e.banner_image_url',
                'e.gallery_json',
                'e.google_maps_url',
                'e.registration_ends_at',
                'v.name_en as venue_name_en',
                'v.name_ar as venue_name_ar',
                'v.city_en',
                'v.city_ar',
                'v.address_en',
                'v.address_ar',
                'tt.name_en as ticket_name_en',
                'tt.name_ar as ticket_name_ar',
                'tt.description_en as ticket_description_en',
                'tt.description_ar as ticket_description_ar',
                'gt.id as ticket_id',
                'gt.ticket_number',
                'gt.pdf_url as ticket_pdf_url',
                'a.qr_status',
                'a.checked_in_at',
                'c.certificate_number',
                'c.status as certificate_status',
                'c.file_url as certificate_file_url'
            ])
            ->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $row
        ]);
    }

    public function tickets(Request $request)
    {
        $options = $request->all();
        $options['status'] = $options['status'] ?? '';
        $options['requireTicket'] = true;

        $result = $this->customerRegistrations($request, $options);
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'data' => $result['rows'],
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'perPage' => $result['perPage']
                ],
            ],
        ]);
    }

    public function showTicket(Request $request, $id)
    {
        $id = (int)$id;
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'Invalid ticket id'], 400);
        }

        $userId = $request->user()->id;

        $row = DB::table('generated_tickets as gt')
            ->join('registrations as r', 'r.id', '=', 'gt.registration_id')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'r.ticket_type_id')
            ->join('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->where('gt.id', $id)
            ->where('d.user_id', $userId)
            ->select([
                'gt.id',
                'gt.ticket_number',
                'gt.pdf_url',
                'gt.generated_at',
                'r.id as registration_id',
                'r.registration_number',
                'r.registration_status',
                'd.full_name',
                'd.email',
                'e.title_en as event_title_en',
                'e.title_ar as event_title_ar',
                'e.summary_en as event_summary_en',
                'e.summary_ar as event_summary_ar',
                'e.description_en as event_description_en',
                'e.description_ar as event_description_ar',
                'e.cover_image_url',
                'e.banner_image_url',
                'e.gallery_json',
                'e.google_maps_url',
                'e.starts_at',
                'e.ends_at',
                'v.name_en as venue_name_en',
                'v.name_ar as venue_name_ar',
                'v.city_en',
                'v.city_ar',
                'v.address_en',
                'v.address_ar',
                'tt.name_en as ticket_name_en',
                'tt.name_ar as ticket_name_ar',
                'a.qr_status',
                'a.checked_in_at'
            ])
            ->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Ticket not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $row
        ]);
    }

    public function ticketQr(Request $request, $id)
    {
        $id = (int)$id;
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'Invalid ticket id'], 400);
        }

        $userId = $request->user()->id;

        $row = DB::table('generated_tickets as gt')
            ->join('registrations as r', 'r.id', '=', 'gt.registration_id')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'r.ticket_type_id')
            ->join('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->where('gt.id', $id)
            ->where('d.user_id', $userId)
            ->select([
                'gt.id',
                'gt.ticket_number',
                'gt.qr_token',
                'r.registration_number',
                'r.registration_status',
                'd.full_name',
                'e.title_en as event_title_en',
                'e.title_ar as event_title_ar',
                'e.starts_at',
                'e.ends_at',
                'tt.name_en as ticket_name_en',
                'tt.name_ar as ticket_name_ar',
                'a.qr_status',
                'a.checked_in_at'
            ])
            ->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Ticket not found'], 404)->header('Cache-Control', 'no-store');
        }

        if ($row->registration_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'QR code will be available once the registration is approved and the ticket is issued',
                'details' => ['state' => 'not_ready']
            ], 409)->header('Cache-Control', 'no-store');
        }

        if ($row->qr_status === 'revoked') {
            return response()->json([
                'success' => false,
                'message' => 'Ticket QR is cancelled',
                'details' => ['state' => 'cancelled']
            ], 409)->header('Cache-Control', 'no-store');
        }

        if ($row->checked_in_at || $row->qr_status === 'used') {
            return response()->json([
                'success' => false,
                'message' => 'Check-in completed',
                'details' => ['state' => 'checked_in', 'checkedInAt' => $row->checked_in_at]
            ], 409)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'qrPayload' => $row->qr_token,
                'ticketNumber' => $row->ticket_number,
                'registrationNumber' => $row->registration_number,
                'ticketStatus' => ($row->checked_in_at || $row->qr_status === 'used') ? 'checked_in' : 'ready',
                'checkedInAt' => $row->checked_in_at,
                'holderName' => $row->full_name,
                'eventTitleEn' => $row->event_title_en,
                'eventTitleAr' => $row->event_title_ar,
                'startsAt' => $row->starts_at,
                'endsAt' => $row->ends_at,
                'ticketNameEn' => $row->ticket_name_en,
                'ticketNameAr' => $row->ticket_name_ar,
            ]
        ])->header('Cache-Control', 'no-store');
    }

    public function certificates(Request $request)
    {
        $options = $request->all();
        $options['requireCertificate'] = true;

        $result = $this->customerRegistrations($request, $options);
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'data' => $result['rows'],
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'perPage' => $result['perPage']
                ],
            ],
        ]);
    }

    public function notifications(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'page' => 1,
                    'perPage' => 10
                ],
            ],
        ]);
    }

    public function reviews(Request $request)
    {
        $userId = $request->user()->id;

        $rows = DB::table('reviews as rv')
            ->join('events as e', 'e.id', '=', 'rv.event_id')
            ->leftJoin('attendees as a', 'a.id', '=', 'rv.attendee_id')
            ->leftJoin('doctors as d', 'd.email', '=', 'a.email')
            ->where(function ($q) use ($userId) {
                $q->where('rv.customer_id', $userId)
                  ->orWhere('d.user_id', $userId);
            })
            ->select([
                'rv.id',
                'rv.rating',
                'rv.title',
                'rv.comment',
                'rv.status',
                'rv.created_at',
                'e.title_en as event_title_en',
                'e.title_ar as event_title_ar'
            ])
            ->orderBy('rv.created_at', 'desc')
            ->orderBy('rv.id', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'data' => $rows,
                'pagination' => [
                    'total' => $rows->count(),
                    'page' => 1,
                    'perPage' => 50
                ],
            ],
        ]);
    }
}
