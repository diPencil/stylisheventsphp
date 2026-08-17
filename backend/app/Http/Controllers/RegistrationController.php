<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RegistrationController extends Controller
{
    private function registrationNumber()
    {
        return 'REG-' . strtoupper(base_convert(now()->timestamp, 10, 36)) . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function orderNumber()
    {
        return 'ORD-' . strtoupper(base_convert(now()->timestamp, 10, 36)) . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function attendeeNumber()
    {
        return 'ATT-' . strtoupper(base_convert(now()->timestamp, 10, 36)) . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function ticketNumber()
    {
        return 'TKT-' . strtoupper(base_convert(now()->timestamp, 10, 36)) . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function qrToken()
    {
        return bin2hex(random_bytes(32));
    }

    private function isEgyptianCountry($countryCode, $countryName, $nationality)
    {
        $code = strtoupper(trim($countryCode ?? ''));
        $normalized = strtolower(trim(($countryName ?? '') . ' ' . ($nationality ?? '')));
        return $code === 'EG' ||
               str_contains($normalized, 'egypt') ||
               str_contains($normalized, 'egyptian') ||
               str_contains($normalized, 'مصر') ||
               str_contains($normalized, 'مصري');
    }

    private function currentPricePeriod($ticketTypeId, $currency)
    {
        $row = DB::table('ticket_price_periods')
            ->where('ticket_type_id', $ticketTypeId)
            ->where('is_active', 1)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at', 'desc')
            ->first();

        if (!$row) return null;

        $selectedPrice = $currency === 'EGP'
            ? (float)($row->price_egp ?? $row->price)
            : (float)($row->price_usd ?? $row->price);

        return array_merge((array)$row, [
            'selected_price' => $selectedPrice,
            'selected_currency' => $currency,
        ]);
    }

    private function activeBankAccount($currency)
    {
        return DB::table('bank_accounts')
            ->where('currency', $currency)
            ->where('is_active', 1)
            ->orderBy('id', 'asc')
            ->first();
    }

    private function hasPaymentMethodColumn()
    {
        return Schema::hasColumn('registrations', 'payment_method');
    }

    private function countActiveReservations($eventId, $ticketTypeId)
    {
        $counts = DB::table('registrations')
            ->where('event_id', $eventId)
            ->whereIn('capacity_reservation_status', ['active', 'held'])
            ->selectRaw('
                COUNT(*) as eventReservedCount,
                SUM(CASE WHEN ticket_type_id = ? THEN 1 ELSE 0 END) as ticketReservedCount
            ', [$ticketTypeId])
            ->first();

        return [
            'ticketReservedCount' => (int)($counts->ticketReservedCount ?? 0),
            'eventReservedCount' => (int)($counts->eventReservedCount ?? 0),
        ];
    }

    private function releaseExpiredReservations($eventId = null, $ticketTypeId = null)
    {
        $query = DB::table('registrations')
            ->where('capacity_reservation_status', 'held')
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<', now());

        if ($eventId) {
            $query->where('event_id', $eventId);
        }
        if ($ticketTypeId) {
            $query->where('ticket_type_id', $ticketTypeId);
        }

        $query->update([
            'capacity_reservation_status' => 'expired',
            'registration_status' => 'expired',
            'capacity_released_at' => now(),
            'capacity_release_reason' => 'reservation_expired'
        ]);
    }

    private function paymentApprovalState($approvalMode)
    {
        if ($approvalMode === 'manual_review') {
            return [
                'registrationStatus' => 'pending_review',
                'paymentStatus' => 'approved',
                'orderStatus' => 'paid',
                'shouldIssueTicket' => false,
            ];
        }
        return [
            'registrationStatus' => 'approved',
            'paymentStatus' => 'approved',
            'orderStatus' => 'paid',
            'shouldIssueTicket' => true,
        ];
    }

    public function index(Request $request)
    {
        if (!$request->user()->hasPermission('registrations.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $status = trim($request->query('status', ''));
        $eventId = (int)$request->query('eventId', 0);
        $limit = min(1000, max(1, (int)$request->query('limit', 300)));
        $offset = max(0, (int)$request->query('offset', 0));
        $search = trim((string)$request->query('search', ''));

        if ($eventId && !$request->user()->hasEventScope($eventId)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $query = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'r.ticket_type_id')
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('users as u', 'u.id', '=', 'r.created_by_user_id')
            ->leftJoin('users as customer_user', 'customer_user.id', '=', 'd.user_id')
            ->leftJoin('roles as customer_role', 'customer_role.id', '=', 'customer_user.role_id')
            ->select([
                'r.id', 'r.registration_number', 'r.event_id', 'r.ticket_type_id', 'r.doctor_id',
                'r.order_id', 'r.source', 'r.registration_status', 'r.payment_status',
                'r.selected_currency', 'r.selected_price', 'r.payment_reference',
                $this->hasPaymentMethodColumn() ? 'r.payment_method' : DB::raw('NULL as payment_method'),
                'r.payment_proof_url',
                'r.created_at', 'o.order_number', 'o.status as order_status', 'o.grand_total',
                'o.currency as order_currency', 'd.full_name as doctor_name', 'd.mobile as doctor_mobile',
                'd.email as doctor_email', 'd.country_code', 'd.country_name', 'd.specialty',
                'd.nationality', 'e.title_en as event_title_en', 'e.title_ar as event_title_ar',
                'tt.name_en as ticket_name_en', 'tt.name_ar as ticket_name_ar', 'gt.ticket_number',
                'gt.pdf_url as ticket_pdf_url', 'u.name as created_by_name',
                DB::raw("COALESCE(customer_role.code, 'guest') as customer_role_code"),
                DB::raw("COALESCE(customer_role.name_en, 'Guest') as customer_role_name_en"),
                DB::raw("COALESCE(customer_role.name_ar, 'ضيف') as customer_role_name_ar")
            ]);

        if ($status) {
            $query->where(function($q) use ($status) {
                $q->where('r.registration_status', $status)
                  ->orWhere('r.payment_status', $status);
            });
        }
        if ($eventId) {
            $query->where('r.event_id', $eventId);
        }
        if ($search !== '') {
            $query->where(function($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('r.registration_number', 'like', $like)
                  ->orWhere('o.order_number', 'like', $like)
                  ->orWhere('d.full_name', 'like', $like)
                  ->orWhere('d.email', 'like', $like)
                  ->orWhere('e.title_en', 'like', $like)
                  ->orWhere('e.title_ar', 'like', $like)
                  ->orWhere('tt.name_en', 'like', $like)
                  ->orWhere('tt.name_ar', 'like', $like);
            });
        }

        $request->user()->applyEventScope($query, 'r.event_id');

        if ($request->query('meta') === 'true') {
            $total = $query->count();
            $rows = $query->orderBy('r.created_at', 'desc')->orderBy('r.id', 'desc')->offset($offset)->limit($limit)->get();
            return response()->json([
                'success' => true,
                'data' => $rows,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset
                ]
            ]);
        }

        $rows = $query->orderBy('r.created_at', 'desc')->orderBy('r.id', 'desc')->offset($offset)->limit($limit)->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->hasPermission('registrations.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $registration = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'r.ticket_type_id')
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('users as u', 'u.id', '=', 'r.created_by_user_id')
            ->leftJoin('users as customer_user', 'customer_user.id', '=', 'd.user_id')
            ->leftJoin('roles as customer_role', 'customer_role.id', '=', 'customer_user.role_id')
            ->where('r.id', $id)
            ->select([
                'r.*', 'o.order_number', 'o.status as order_status', 'o.grand_total',
                'o.currency as order_currency', 'd.full_name as doctor_name', 'd.mobile as doctor_mobile',
                'd.email as doctor_email', 'd.address as doctor_address', 'd.country_code',
                'd.country_name', 'd.city', 'd.specialty', 'd.nationality',
                'e.title_en as event_title_en', 'e.title_ar as event_title_ar', 'e.starts_at', 'e.ends_at',
                'tt.name_en as ticket_name_en', 'tt.name_ar as ticket_name_ar', 'gt.ticket_number',
                'gt.qr_token', 'gt.pdf_url as ticket_pdf_url', 'u.name as created_by_name',
                DB::raw("COALESCE(customer_role.code, 'guest') as customer_role_code"),
                DB::raw("COALESCE(customer_role.name_en, 'Guest') as customer_role_name_en"),
                DB::raw("COALESCE(customer_role.name_ar, 'ضيف') as customer_role_name_ar")
            ])
            ->first();

        if (!$registration) {
            return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        }
        if (!$request->user()->hasEventScope($registration->event_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return response()->json(['success' => true, 'data' => $registration]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'eventId' => 'required|integer|min:1',
            'ticketTypeId' => 'required|integer|min:1',
            'source' => 'nullable|string|in:online,manual,kiosk',
            'fullName' => 'required|string|min:2',
            'mobile' => 'required|string|min:7',
            'email' => 'required|email',
            'address' => 'nullable|string',
            'countryCode' => 'required|string|size:2',
            'countryName' => 'required|string|min:2',
            'city' => 'required|string|min:2',
            'specialty' => 'required|string|min:2',
            'nationality' => 'required|string|min:2',
            'preferredLanguage' => 'nullable|string|in:ar,en',
            'paymentReference' => 'nullable|string',
            'paymentMethod' => 'nullable|string|max:100',
            'paymentProofUrl' => 'nullable|string',
        ]);

        $input = array_merge($validated, [
            'source' => $validated['source'] ?? 'online',
            'preferredLanguage' => $validated['preferredLanguage'] ?? 'en',
            'address' => $validated['address'] ?? '',
            'paymentReference' => $validated['paymentReference'] ?? null,
            'paymentMethod' => $validated['paymentMethod'] ?? null,
            'paymentProofUrl' => $validated['paymentProofUrl'] ?? null,
        ]);

        $currency = $this->isEgyptianCountry($input['countryCode'], $input['countryName'], $input['nationality']) ? 'EGP' : 'USD';
        $pricePeriod = $this->currentPricePeriod($input['ticketTypeId'], $currency);
        if (!$pricePeriod) {
            return response()->json(['success' => false, 'message' => 'No active price period for this ticket type'], 422);
        }

        $event = DB::table('events')->where('id', $input['eventId'])->select('id', 'title_en')->first();
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        $ticketType = DB::table('ticket_types')
            ->where('id', $input['ticketTypeId'])
            ->where('event_id', $input['eventId'])
            ->where('is_active', 1)
            ->select('id', 'event_id', 'name_en', 'quota')
            ->first();
        if (!$ticketType) {
            return response()->json(['success' => false, 'message' => 'Ticket type not found for this event'], 404);
        }

        $registrationData = DB::transaction(function () use ($input, $pricePeriod) {
            $existingDoctor = DB::table('doctors')->where('email', $input['email'])->first();
            if ($existingDoctor) {
                $doctorId = $existingDoctor->id;
                DB::table('doctors')->where('id', $doctorId)->update([
                    'full_name' => $input['fullName'],
                    'mobile' => $input['mobile'],
                    'address' => $input['address'],
                    'country_code' => strtoupper($input['countryCode']),
                    'country_name' => $input['countryName'],
                    'city' => $input['city'],
                    'specialty' => $input['specialty'],
                    'nationality' => $input['nationality'],
                    'preferred_language' => $input['preferredLanguage'],
                ]);
            } else {
                $doctorId = DB::table('doctors')->insertGetId([
                    'full_name' => $input['fullName'],
                    'mobile' => $input['mobile'],
                    'email' => $input['email'],
                    'address' => $input['address'],
                    'country_code' => strtoupper($input['countryCode']),
                    'country_name' => $input['countryName'],
                    'city' => $input['city'],
                    'specialty' => $input['specialty'],
                    'nationality' => $input['nationality'],
                    'preferred_language' => $input['preferredLanguage'],
                ]);
            }

            $orderId = DB::table('orders')->insertGetId([
                'event_id' => $input['eventId'],
                'order_number' => $this->orderNumber(),
                'status' => 'pending_payment',
                'subtotal' => $pricePeriod['selected_price'],
                'grand_total' => $pricePeriod['selected_price'],
                'currency' => $pricePeriod['selected_currency'],
                'customer_name' => $input['fullName'],
                'customer_email' => $input['email'],
                'customer_phone' => $input['mobile'],
            ]);

            $status = ($input['paymentReference'] || $input['paymentProofUrl']) ? 'pending_verification' : 'pending_payment';
            $nextRegistrationNumber = $this->registrationNumber();

            $registrationPayload = [
                'registration_number' => $nextRegistrationNumber,
                'doctor_id' => $doctorId,
                'event_id' => $input['eventId'],
                'ticket_type_id' => $input['ticketTypeId'],
                'order_id' => $orderId,
                'source' => $input['source'],
                'registration_status' => $status,
                'payment_status' => 'pending',
                'selected_currency' => $pricePeriod['selected_currency'],
                'selected_price' => $pricePeriod['selected_price'],
                'selected_price_period_id' => $pricePeriod['id'],
                'payment_reference' => $input['paymentReference'],
                'payment_proof_url' => $input['paymentProofUrl'],
            ];

            if ($this->hasPaymentMethodColumn()) {
                $registrationPayload['payment_method'] = $input['paymentMethod'];
            }

            $regId = DB::table('registrations')->insertGetId($registrationPayload);

            return [
                'id' => $regId,
                'registrationNumber' => $nextRegistrationNumber,
                'doctorId' => $doctorId,
                'orderId' => $orderId,
                'currency' => $pricePeriod['selected_currency'],
                'price' => $pricePeriod['selected_price'],
                'status' => $status,
            ];
        });

        $bankAccount = $this->activeBankAccount($currency);

        return response()->json([
            'success' => true,
            'message' => 'Registration created. Payment is pending verification.',
            'data' => array_merge($registrationData, ['bankAccount' => $bankAccount])
        ]);
    }

    public function storeManual(Request $request)
    {
        if (!$request->user()->hasPermission('registrations.create_manual')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'eventId' => 'required|integer|min:1',
            'ticketTypeId' => 'required|integer|min:1',
            'source' => 'nullable|string|in:online,manual,kiosk',
            'fullName' => 'required|string|min:2',
            'mobile' => 'required|string|min:7',
            'email' => 'required|email',
            'address' => 'nullable|string',
            'countryCode' => 'required|string|size:2',
            'countryName' => 'required|string|min:2',
            'city' => 'required|string|min:2',
            'specialty' => 'required|string|min:2',
            'nationality' => 'required|string|min:2',
            'preferredLanguage' => 'nullable|string|in:ar,en',
            'paymentReference' => 'nullable|string',
            'paymentMethod' => 'nullable|string|max:100',
            'paymentProofUrl' => 'nullable|string',
            'paymentStatus' => 'nullable|string|in:paid,pending',
            'sendEmail' => 'nullable|boolean',
        ]);

        $input = array_merge($validated, [
            'source' => $validated['source'] ?? 'online',
            'preferredLanguage' => $validated['preferredLanguage'] ?? 'en',
            'address' => $validated['address'] ?? '',
            'paymentReference' => $validated['paymentReference'] ?? null,
            'paymentMethod' => $validated['paymentMethod'] ?? null,
            'paymentProofUrl' => $validated['paymentProofUrl'] ?? null,
            'paymentStatus' => $validated['paymentStatus'] ?? 'pending',
            'sendEmail' => $validated['sendEmail'] ?? false,
        ]);

        $currency = $this->isEgyptianCountry($input['countryCode'], $input['countryName'], $input['nationality']) ? 'EGP' : 'USD';
        $pricePeriod = $this->currentPricePeriod($input['ticketTypeId'], $currency);
        if (!$pricePeriod) {
            return response()->json(['success' => false, 'message' => 'No active price period for this ticket type'], 422);
        }

        $registrationData = DB::transaction(function () use ($request, $input, $pricePeriod) {
            $event = DB::table('events')->where('id', $input['eventId'])->lockForUpdate()->first();
            if (!$event) throw ValidationException::withMessages(['eventId' => 'Event not found']);

            $ticketType = DB::table('ticket_types')
                ->where('id', $input['ticketTypeId'])
                ->where('event_id', $input['eventId'])
                ->where('is_active', 1)
                ->lockForUpdate()
                ->first();
            if (!$ticketType) throw ValidationException::withMessages(['ticketTypeId' => 'Ticket type not found for this event']);

            $counts = $this->countActiveReservations($input['eventId'], $input['ticketTypeId']);
            $eventCapacityFull = $event->max_attendees && $counts['eventReservedCount'] >= $event->max_attendees;
            $ticketCapacityFull = $ticketType->quota && $counts['ticketReservedCount'] >= $ticketType->quota;

            if ($eventCapacityFull || $ticketCapacityFull) {
                return response()->json(['success' => false, 'message' => 'Capacity exceeded'], 409);
            }

            $existingDoctor = DB::table('doctors')->where('email', $input['email'])->first();
            if ($existingDoctor) {
                $doctorId = $existingDoctor->id;
                DB::table('doctors')->where('id', $doctorId)->update([
                    'full_name' => $input['fullName'], 'mobile' => $input['mobile'], 'address' => $input['address'],
                    'country_code' => strtoupper($input['countryCode']), 'country_name' => $input['countryName'],
                    'city' => $input['city'], 'specialty' => $input['specialty'], 'nationality' => $input['nationality'],
                    'preferred_language' => $input['preferredLanguage'],
                ]);
            } else {
                $doctorId = DB::table('doctors')->insertGetId([
                    'full_name' => $input['fullName'], 'mobile' => $input['mobile'], 'email' => $input['email'],
                    'address' => $input['address'], 'country_code' => strtoupper($input['countryCode']),
                    'country_name' => $input['countryName'], 'city' => $input['city'], 'specialty' => $input['specialty'],
                    'nationality' => $input['nationality'], 'preferred_language' => $input['preferredLanguage'],
                ]);
            }

            $isPaid = $input['paymentStatus'] === 'paid';
            $orderStatus = $isPaid ? 'paid' : 'pending_payment';

            $orderId = DB::table('orders')->insertGetId([
                'event_id' => $input['eventId'], 'order_number' => $this->orderNumber(),
                'status' => $orderStatus, 'subtotal' => $pricePeriod['selected_price'],
                'grand_total' => $pricePeriod['selected_price'], 'currency' => $pricePeriod['selected_currency'],
                'customer_name' => $input['fullName'], 'customer_email' => $input['email'], 'customer_phone' => $input['mobile'],
            ]);

            $approvalState = $isPaid
                ? $this->paymentApprovalState($event->registration_approval_mode ?? 'automatic')
                : ['registrationStatus' => 'pending_payment', 'paymentStatus' => 'pending', 'shouldIssueTicket' => false];

            if ($isPaid && $approvalState['paymentStatus'] === 'pending') {
                $approvalState['paymentStatus'] = 'approved';
                $approvalState['registrationStatus'] = 'approved';
                $approvalState['shouldIssueTicket'] = true;
            }

            $nextRegistrationNumber = $this->registrationNumber();
            $registrationPayload = [
                'registration_number' => $nextRegistrationNumber, 'doctor_id' => $doctorId,
                'event_id' => $input['eventId'], 'ticket_type_id' => $input['ticketTypeId'], 'order_id' => $orderId,
                'source' => 'manual', 'registration_status' => $approvalState['registrationStatus'],
                'payment_status' => $approvalState['paymentStatus'], 'selected_currency' => $pricePeriod['selected_currency'],
                'selected_price' => $pricePeriod['selected_price'], 'selected_price_period_id' => $pricePeriod['id'],
                'payment_reference' => $input['paymentReference'], 'payment_proof_url' => $input['paymentProofUrl'],
                'created_by_user_id' => $request->user()->id, 'capacity_reservation_status' => 'active',
            ];

            if ($this->hasPaymentMethodColumn()) {
                $registrationPayload['payment_method'] = $input['paymentMethod'];
            }

            $regId = DB::table('registrations')->insertGetId($registrationPayload);

            $ticketInfo = [];
            if ($approvalState['shouldIssueTicket']) {
                $token = $this->qrToken();
                $attendeeId = DB::table('attendees')->insertGetId([
                    'order_id' => $orderId, 'event_id' => $input['eventId'], 'ticket_type_id' => $input['ticketTypeId'],
                    'attendee_number' => $this->attendeeNumber(), 'full_name' => $input['fullName'],
                    'email' => $input['email'], 'phone' => $input['mobile'], 'job_title' => $input['specialty'],
                    'qr_token' => $token,
                ]);

                $nextTicketNumber = $this->ticketNumber();
                $ticketId = DB::table('generated_tickets')->insertGetId([
                    'registration_id' => $regId, 'attendee_id' => $attendeeId,
                    'ticket_number' => $nextTicketNumber, 'qr_token' => $token, 'generated_at' => now(),
                ]);
                $ticketInfo = ['attendeeId' => $attendeeId, 'ticketId' => $ticketId, 'ticketNumber' => $nextTicketNumber, 'qrToken' => $token];
            }

            return [
                'id' => $regId, 'registrationNumber' => $nextRegistrationNumber, 'doctorId' => $doctorId,
                'orderId' => $orderId, 'currency' => $pricePeriod['selected_currency'], 'price' => $pricePeriod['selected_price'],
                'status' => $approvalState['registrationStatus'], 'ticketInfo' => (object)$ticketInfo
            ];
        });

        if ($registrationData instanceof \Illuminate\Http\JsonResponse) {
            return $registrationData; // Return capacity exceeded error
        }

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id, 'action' => 'registrations.manual_create',
            'entity_type' => 'registration', 'entity_id' => $registrationData['id'],
            'metadata_json' => json_encode(['source' => 'manual', 'eventId' => $input['eventId'], 'ticketTypeId' => $input['ticketTypeId']]),
            'created_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Manual booking created successfully.',
            'data' => $registrationData
        ]);
    }

    public function updatePaymentProof(Request $request, $id)
    {
        $validated = $request->validate([
            'paymentReference' => 'nullable|string',
            'paymentMethod' => 'nullable|string|max:100',
            'paymentProofUrl' => 'nullable|string|min:2',
        ]);

        $registration = DB::table('registrations')->where('id', $id)->first();
        if (!$registration) return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        if ($registration->registration_status === 'expired' || $registration->capacity_reservation_status === 'expired') {
            return response()->json(['success' => false, 'message' => 'Reservation has expired. Please start a new checkout or contact support.'], 409);
        }

        if (empty($validated['paymentReference']) && empty($validated['paymentProofUrl'])) {
            return response()->json(['success' => false, 'message' => 'Payment reference or proof is required'], 422);
        }

        $paymentUpdate = [
            'payment_reference' => $validated['paymentReference'] ?? null,
            'payment_proof_url' => $validated['paymentProofUrl'] ?? null,
            'registration_status' => 'pending_verification',
            'payment_status' => 'pending',
            'reservation_expires_at' => null,
            'capacity_reservation_status' => 'active',
            'capacity_released_at' => null,
            'capacity_release_reason' => null
        ];

        if ($this->hasPaymentMethodColumn()) {
            $paymentUpdate['payment_method'] = $validated['paymentMethod'] ?? null;
        }

        DB::table('registrations')->where('id', $id)->update($paymentUpdate);

        return response()->json(['success' => true, 'message' => 'Payment proof submitted', 'data' => ['id' => (int)$id]]);
    }

    public function reviewPayment(Request $request, $id)
    {
        if (!$request->user()->hasPermission('payments.verify')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:approved,rejected',
            'reviewedByUserId' => 'nullable|integer|min:1',
            'rejectionReason' => 'nullable|string',
        ]);

        $registration = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->where('r.id', $id)
            ->select('r.id', 'r.order_id', 'r.event_id', 'r.ticket_type_id', 'r.registration_status', 'r.payment_status', 'r.capacity_reservation_status', 'r.reservation_expires_at', 'd.full_name', 'd.email', 'd.mobile', 'd.specialty')
            ->first();

        if (!$registration) return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        if (!$request->user()->hasEventScope($registration->event_id)) return response()->json(['success' => false, 'message' => 'Forbidden'], 403);

        $reviewedByUserId = $validated['reviewedByUserId'] ?? null;
        $rejectionReason = $validated['rejectionReason'] ?? null;

        if ($validated['status'] === 'rejected') {
            DB::table('registrations')->where('id', $registration->id)->update([
                'registration_status' => 'rejected',
                'payment_status' => 'rejected',
                'payment_reviewed_by_user_id' => $reviewedByUserId,
                'payment_reviewed_at' => now(),
                'payment_rejection_reason' => $rejectionReason,
                'capacity_reservation_status' => 'released',
                'capacity_released_at' => DB::raw('COALESCE(capacity_released_at, NOW())'),
                'capacity_release_reason' => DB::raw("COALESCE(capacity_release_reason, 'payment_rejected')"),
            ]);
            DB::table('orders')->where('id', $registration->order_id)->update(['status' => 'pending_payment']);
            return response()->json(['success' => true, 'message' => 'Payment rejected', 'data' => ['id' => $registration->id, 'status' => 'rejected']]);
        }

        $approved = DB::transaction(function () use ($registration, $reviewedByUserId) {
            $event = DB::table('events')->where('id', $registration->event_id)->lockForUpdate()->first();
            $ticketType = DB::table('ticket_types')->where('id', $registration->ticket_type_id)->lockForUpdate()->first();

            $this->releaseExpiredReservations($registration->event_id, $registration->ticket_type_id);

            $fresh = DB::table('registrations')->where('id', $registration->id)->lockForUpdate()->first();
            if (!$fresh) throw new \Exception('Registration not found');

            if ($fresh->capacity_reservation_status === 'expired' || $fresh->registration_status === 'expired') {
                $counts = $this->countActiveReservations($registration->event_id, $registration->ticket_type_id);
                $eventCapacityFull = $event->max_attendees && $counts['eventReservedCount'] + 1 > $event->max_attendees;
                $ticketCapacityFull = $ticketType->quota && $counts['ticketReservedCount'] + 1 > $ticketType->quota;
                if ($eventCapacityFull || $ticketCapacityFull) {
                    return response()->json(['success' => false, 'message' => 'Capacity is no longer available for this expired reservation'], 409);
                }
            }

            $approvalState = $this->paymentApprovalState($event->registration_approval_mode ?? 'automatic');

            DB::table('registrations')->where('id', $registration->id)->update([
                'registration_status' => $approvalState['registrationStatus'],
                'payment_status' => $approvalState['paymentStatus'],
                'capacity_reservation_status' => 'active',
                'capacity_released_at' => null,
                'capacity_release_reason' => null,
                'reservation_expires_at' => null,
                'payment_reviewed_by_user_id' => $reviewedByUserId,
                'payment_reviewed_at' => now(),
                'payment_rejection_reason' => null,
            ]);

            DB::table('orders')->where('id', $registration->order_id)->update(['status' => $approvalState['orderStatus']]);

            if (!$approvalState['shouldIssueTicket']) {
                return [
                    'pendingReview' => true,
                    'registrationStatus' => $approvalState['registrationStatus'],
                    'paymentStatus' => $approvalState['paymentStatus'],
                ];
            }

            $existingAttendee = DB::table('attendees')->where('order_id', $registration->order_id)
                ->where('event_id', $registration->event_id)->where('ticket_type_id', $registration->ticket_type_id)->first();

            $attendeeId = $existingAttendee->id ?? null;
            $token = $existingAttendee->qr_token ?? $this->qrToken();

            if (!$attendeeId) {
                $attendeeId = DB::table('attendees')->insertGetId([
                    'order_id' => $registration->order_id, 'event_id' => $registration->event_id,
                    'ticket_type_id' => $registration->ticket_type_id, 'attendee_number' => $this->attendeeNumber(),
                    'full_name' => $registration->full_name, 'email' => $registration->email,
                    'phone' => $registration->mobile, 'job_title' => $registration->specialty, 'qr_token' => $token,
                ]);
            }

            $existingTicket = DB::table('generated_tickets')->where('registration_id', $registration->id)->first();
            if ($existingTicket) {
                return [
                    'attendeeId' => $attendeeId, 'ticketId' => $existingTicket->id,
                    'ticketNumber' => $existingTicket->ticket_number, 'qrToken' => $token,
                ];
            }

            $nextTicketNumber = $this->ticketNumber();
            $ticketId = DB::table('generated_tickets')->insertGetId([
                'registration_id' => $registration->id, 'attendee_id' => $attendeeId,
                'ticket_number' => $nextTicketNumber, 'qr_token' => $token, 'generated_at' => now(),
            ]);

            return [
                'attendeeId' => $attendeeId, 'ticketId' => $ticketId,
                'ticketNumber' => $nextTicketNumber, 'qrToken' => $token,
            ];
        });

        if ($approved instanceof \Illuminate\Http\JsonResponse) return $approved;

        $msg = !empty($approved['pendingReview']) ? 'Payment approved. Registration is pending manual review.' : 'Payment approved and ticket generated';
        return response()->json([
            'success' => true, 'message' => $msg,
            'data' => array_merge(['id' => $registration->id, 'status' => !empty($approved['pendingReview']) ? 'pending_review' : 'approved'], $approved)
        ]);
    }

    public function reviewRegistration(Request $request, $id)
    {
        if (!$request->user()->hasPermission('registrations.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:approved,rejected',
            'rejectionReason' => 'nullable|string',
        ]);

        $registration = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->where('r.id', $id)
            ->select('r.id', 'r.order_id', 'r.event_id', 'r.ticket_type_id', 'r.registration_status', 'r.payment_status', 'd.full_name', 'd.email', 'd.mobile', 'd.specialty')
            ->first();

        if (!$registration) return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        if (!$request->user()->hasEventScope($registration->event_id)) return response()->json(['success' => false, 'message' => 'Forbidden'], 403);

        if ($validated['status'] === 'rejected') {
            DB::table('registrations')->where('id', $registration->id)->update([
                'registration_status' => 'rejected',
                'capacity_reservation_status' => 'released',
                'capacity_released_at' => DB::raw('COALESCE(capacity_released_at, NOW())'),
                'capacity_release_reason' => DB::raw("COALESCE(capacity_release_reason, 'registration_rejected')"),
                'payment_rejection_reason' => $validated['rejectionReason'] ?? null,
                'updated_at' => now(),
            ]);
            return response()->json(['success' => true, 'message' => 'Registration rejected', 'data' => ['id' => $registration->id, 'status' => 'rejected']]);
        }

        if ($registration->payment_status !== 'approved') {
            return response()->json(['success' => false, 'message' => 'Payment must be approved before registration approval can issue a ticket'], 409);
        }

        $approved = DB::transaction(function () use ($registration) {
            $existingAttendee = DB::table('attendees')->where('order_id', $registration->order_id)
                ->where('event_id', $registration->event_id)->where('ticket_type_id', $registration->ticket_type_id)->first();

            $attendeeId = $existingAttendee->id ?? null;
            $token = $existingAttendee->qr_token ?? $this->qrToken();

            if (!$attendeeId) {
                $attendeeId = DB::table('attendees')->insertGetId([
                    'order_id' => $registration->order_id, 'event_id' => $registration->event_id,
                    'ticket_type_id' => $registration->ticket_type_id, 'attendee_number' => $this->attendeeNumber(),
                    'full_name' => $registration->full_name, 'email' => $registration->email,
                    'phone' => $registration->mobile, 'job_title' => $registration->specialty, 'qr_token' => $token,
                ]);
            }

            $existingTicket = DB::table('generated_tickets')->where('registration_id', $registration->id)->first();
            $ticketId = $existingTicket->id ?? null;
            $generatedTicketNumber = $existingTicket->ticket_number ?? null;

            if (!$ticketId) {
                $generatedTicketNumber = $this->ticketNumber();
                $ticketId = DB::table('generated_tickets')->insertGetId([
                    'registration_id' => $registration->id, 'attendee_id' => $attendeeId,
                    'ticket_number' => $generatedTicketNumber, 'qr_token' => $token, 'generated_at' => now(),
                ]);
            }

            DB::table('registrations')->where('id', $registration->id)->update([
                'registration_status' => 'approved',
                'capacity_reservation_status' => 'active',
                'capacity_released_at' => null,
                'capacity_release_reason' => null,
                'updated_at' => now(),
            ]);

            return ['attendeeId' => $attendeeId, 'ticketId' => $ticketId, 'ticketNumber' => $generatedTicketNumber];
        });

        return response()->json(['success' => true, 'message' => 'Registration approved and ticket generated', 'data' => array_merge(['id' => $registration->id, 'status' => 'approved'], $approved)]);
    }

    public function updateOrderStatus(Request $request, $id)
    {
        if (!$request->user()->hasPermission('registrations.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:paid,cancelled,refunded',
            'reviewedByUserId' => 'nullable|integer|min:1',
        ]);

        $registration = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->where('r.id', $id)
            ->select('r.id', 'r.order_id', 'r.event_id', 'r.ticket_type_id', 'd.full_name', 'd.email', 'd.mobile', 'd.specialty')
            ->first();

        if (!$registration) return response()->json(['success' => false, 'message' => 'Registration not found'], 404);

        $registrationStatus = $validated['status'] === 'cancelled' ? 'cancelled' : 'approved';
        $paymentStatus = in_array($validated['status'], ['paid', 'refunded']) ? 'approved' : 'pending';

        $updated = DB::transaction(function () use ($registration, $validated, $registrationStatus, $paymentStatus) {
            DB::table('registrations')->where('id', $registration->id)->update([
                'registration_status' => $registrationStatus,
                'payment_status' => $paymentStatus,
                'updated_at' => now(),
            ]);

            DB::table('orders')->where('id', $registration->order_id)->update(['status' => $validated['status']]);

            if ($validated['status'] === 'cancelled') {
                DB::table('attendees')->where('order_id', $registration->order_id)->update(['qr_status' => 'revoked']);
                return null;
            }

            if ($validated['status'] !== 'paid') {
                return null;
            }

            $existingAttendee = DB::table('attendees')->where('order_id', $registration->order_id)
                ->where('event_id', $registration->event_id)->where('ticket_type_id', $registration->ticket_type_id)->first();

            $attendeeId = $existingAttendee->id ?? null;
            $token = $existingAttendee->qr_token ?? $this->qrToken();

            if (!$attendeeId) {
                $attendeeId = DB::table('attendees')->insertGetId([
                    'order_id' => $registration->order_id, 'event_id' => $registration->event_id,
                    'ticket_type_id' => $registration->ticket_type_id, 'attendee_number' => $this->attendeeNumber(),
                    'full_name' => $registration->full_name, 'email' => $registration->email,
                    'phone' => $registration->mobile, 'job_title' => $registration->specialty, 'qr_token' => $token,
                ]);
            }

            $existingTicket = DB::table('generated_tickets')->where('registration_id', $registration->id)->first();
            if ($existingTicket) {
                return [
                    'attendeeId' => $attendeeId, 'ticketId' => $existingTicket->id,
                    'ticketNumber' => $existingTicket->ticket_number, 'qrToken' => $token,
                ];
            }

            $nextTicketNumber = $this->ticketNumber();
            $ticketId = DB::table('generated_tickets')->insertGetId([
                'registration_id' => $registration->id, 'attendee_id' => $attendeeId,
                'ticket_number' => $nextTicketNumber, 'qr_token' => $token, 'generated_at' => now(),
            ]);

            return [
                'attendeeId' => $attendeeId, 'ticketId' => $ticketId,
                'ticketNumber' => $nextTicketNumber, 'qrToken' => $token,
            ];
        });

        return response()->json(['success' => true, 'message' => 'Order status updated', 'data' => ['id' => $registration->id, 'status' => $validated['status'], 'generated' => $updated]]);
    }
}
