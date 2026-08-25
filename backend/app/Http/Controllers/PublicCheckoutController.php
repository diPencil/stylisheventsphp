<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Services\UserNotificationService;

class PublicCheckoutController extends Controller
{
    private function activeCapacitySql($alias = 'r')
    {
        return "$alias.registration_status NOT IN ('rejected', 'cancelled', 'expired')
                AND COALESCE($alias.capacity_reservation_status, 'active') = 'active'";
    }

    private function reservationExpirySql($hours)
    {
        $normalizedHours = max(1, min((int)($hours ?: env('CHECKOUT_RESERVATION_HOURS', 72)), 720));
        return "DATE_ADD(NOW(), INTERVAL $normalizedHours HOUR)";
    }

    private function releaseExpiredReservations($eventId = null, $ticketTypeId = null)
    {
        $query = DB::table('registrations')
            ->where('registration_status', 'pending_payment')
            ->whereRaw("COALESCE(capacity_reservation_status, 'active') = 'active'")
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<=', now());

        if ($eventId) $query->where('event_id', $eventId);
        if ($ticketTypeId) $query->where('ticket_type_id', $ticketTypeId);

        $affected = $query->update([
            'registration_status' => 'expired',
            'payment_status' => 'expired',
            'capacity_reservation_status' => 'expired',
            'capacity_released_at' => DB::raw('COALESCE(capacity_released_at, NOW())'),
            'capacity_release_reason' => DB::raw("COALESCE(capacity_release_reason, 'payment_deadline_expired')")
        ]);

        if ($affected > 0) {
            $orderQuery = DB::table('orders')
                ->join('registrations as r', 'r.order_id', '=', 'orders.id')
                ->where('r.registration_status', 'expired')
                ->where('r.capacity_release_reason', 'payment_deadline_expired')
                ->where('orders.status', 'pending_payment');

            if ($eventId) $orderQuery->where('r.event_id', $eventId);
            if ($ticketTypeId) $orderQuery->where('r.ticket_type_id', $ticketTypeId);

            $orderQuery->update(['orders.status' => 'expired']);
        }

        return $affected;
    }

    private function countActiveReservations($eventId, $ticketTypeId)
    {
        $result = DB::table('registrations as r')
            ->where('r.event_id', $eventId)
            ->whereRaw($this->activeCapacitySql('r'))
            ->selectRaw("SUM(CASE WHEN r.ticket_type_id = ? THEN 1 ELSE 0 END) as ticket_reserved_count", [$ticketTypeId])
            ->selectRaw("COUNT(*) as event_reserved_count")
            ->first();

        return [
            'ticketReservedCount' => (int)($result->ticket_reserved_count ?? 0),
            'eventReservedCount' => (int)($result->event_reserved_count ?? 0),
        ];
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

        return (object)[
            'id' => $row->id,
            'selected_currency' => $currency,
            'selected_price' => $currency === 'EGP' ? (float)($row->price_egp ?? $row->price) : (float)($row->price_usd ?? $row->price),
        ];
    }

    private function issueTicket($registrationId)
    {
        $registration = DB::table('registrations')->where('id', $registrationId)->first();
        if (!$registration) return null;

        $order = DB::table('orders')->where('id', $registration->order_id)->first();

        $existingAttendee = DB::table('attendees')
            ->where('order_id', $registration->order_id)
            ->where('event_id', $registration->event_id)
            ->where('ticket_type_id', $registration->ticket_type_id)
            ->first();

        $attendeeId = $existingAttendee->id ?? null;
        $token = $existingAttendee->qr_token ?? bin2hex(random_bytes(32));

        if (!$attendeeId) {
            $attendeeId = DB::table('attendees')->insertGetId([
                'order_id' => $registration->order_id,
                'event_id' => $registration->event_id,
                'ticket_type_id' => $registration->ticket_type_id,
                'attendee_number' => 'ATT-' . strtoupper(base_convert(time(), 10, 36) . '-' . bin2hex(random_bytes(3))),
                'full_name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'qr_token' => $token
            ]);
        }

        $existingTicket = DB::table('generated_tickets')->where('registration_id', $registration->id)->first();
        if ($existingTicket) {
            return ['ticketId' => $existingTicket->id, 'ticketNumber' => $existingTicket->ticket_number];
        }

        $ticketNumber = 'TKT-' . strtoupper(base_convert(time(), 10, 36) . '-' . bin2hex(random_bytes(3)));
        $ticketId = DB::table('generated_tickets')->insertGetId([
            'registration_id' => $registration->id,
            'attendee_id' => $attendeeId,
            'ticket_number' => $ticketNumber,
            'qr_token' => $token,
            'generated_at' => now(),
        ]);

        return ['ticketId' => $ticketId, 'ticketNumber' => $ticketNumber];
    }

    private function normalizeEventPolicy($event)
    {
        $maxPerCheckout = (int)($event->max_tickets_per_checkout ?? 1);
        $holdOverride = (int)($event->capacity_hold_hours_override ?? 0);
        return (object)[
            'publicRegistrationEnabled' => (int)($event->public_registration_enabled ?? 1) === 1,
            'approvalMode' => in_array($event->registration_approval_mode, ['automatic', 'manual_review']) ? $event->registration_approval_mode : 'automatic',
            'access' => in_array($event->registration_access, ['guest_allowed', 'login_required']) ? $event->registration_access : 'guest_allowed',
            'maxTicketsPerCheckout' => max(1, min($maxPerCheckout, 1)),
            'capacityHoldHoursOverride' => $holdOverride > 0 ? min($holdOverride, 720) : null,
            'manualPaymentEnabled' => (int)($event->manual_payment_enabled ?? 1) === 1,
        ];
    }

    private function eventState($event, $soldOut = false)
    {
        $now = now()->timestamp;
        $startsAt = $event->starts_at ? Carbon::parse($event->starts_at)->timestamp : 0;
        $endsAt = $event->ends_at ? Carbon::parse($event->ends_at)->timestamp : 0;
        $opensAt = $event->registration_starts_at ? Carbon::parse($event->registration_starts_at)->timestamp : 0;
        $closesAt = $event->registration_ends_at ? Carbon::parse($event->registration_ends_at)->timestamp : 0;

        if ((int)($event->public_registration_enabled ?? 1) !== 1) return 'disabled';
        if (in_array($event->status, ['cancelled', 'disabled'])) return 'cancelled';
        if ($event->status === 'sold_out' || $soldOut) return 'sold_out';
        if ($endsAt && $endsAt < $now) return 'ended';
        if ($opensAt && $opensAt > $now) return 'opens_soon';
        if ($closesAt && $closesAt < $now) return 'closed';
        if ($startsAt && $startsAt < $now) return 'closed';
        return 'open';
    }

    private function isEgyptianCountry($countryCode = '', $countryName = '', $nationality = '')
    {
        $normalized = strtolower(trim("$countryName $nationality"));
        return strtoupper(trim($countryCode)) === 'EG'
            || str_contains($normalized, 'egypt')
            || str_contains($normalized, 'مصر')
            || str_contains($normalized, 'مصري');
    }

    private function payloadHash($payload)
    {
        return hash('sha256', json_encode($payload));
    }

    private function tokenHash($token)
    {
        return hash('sha256', (string)($token ?: ''));
    }

    private function confirmationCookieName($reference)
    {
        return 'se_conf_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$reference);
    }

    private function getCheckoutInitialState($isFree, $hasPaymentProof, $approvalMode)
    {
        if ($isFree) {
            return (object)[
                'registrationStatus' => $approvalMode === 'manual_review' ? 'pending_review' : 'approved',
                'paymentStatus' => 'approved',
                'orderStatus' => 'paid',
                'shouldIssueTicket' => $approvalMode !== 'manual_review',
            ];
        }

        return (object)[
            'registrationStatus' => $hasPaymentProof ? 'pending_verification' : 'pending_payment',
            'paymentStatus' => 'pending',
            'orderStatus' => 'pending_payment',
            'shouldIssueTicket' => false,
        ];
    }

    private function paymentMethods($currency = null)
    {
        $query = DB::table('bank_accounts')
            ->where('is_active', 1)
            ->orderBy('currency')
            ->orderBy('id');

        if ($currency) {
            $query->where('currency', strtoupper($currency));
        }

        return $query->get()->map(function ($account) {
            return [
                'id' => 'bank_account:' . $account->id,
                'type' => 'bank_transfer',
                'label_en' => trim(($account->bank_name ?: 'Bank transfer') . ' - ' . ($account->account_name ?: 'Account')),
                'label_ar' => trim(($account->bank_name ?: 'تحويل بنكي') . ' - ' . ($account->account_name ?: 'الحساب')),
                'currency' => $account->currency,
                'bank_name' => $account->bank_name,
                'account_name' => $account->account_name,
                'account_number' => $account->account_number,
                'iban' => $account->iban,
                'swift_code' => $account->swift_code,
                'requires_reference' => true,
            ];
        })->values()->all();
    }

    private function hasPaymentMethodColumn()
    {
        return Schema::hasColumn('registrations', 'payment_method');
    }

    private function registrationSummary($registrationId)
    {
        $registration = DB::table('registrations as r')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as t', 't.id', '=', 'r.ticket_type_id')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->select(
                'r.id', 'r.registration_number', 'r.registration_status', 'r.payment_status',
                'r.selected_currency', 'r.selected_price', 'r.payment_reference',
                $this->hasPaymentMethodColumn() ? 'r.payment_method' : DB::raw('NULL as payment_method'),
                'r.payment_proof_url',
                'r.reservation_expires_at', 'r.capacity_reservation_status', 'r.capacity_released_at',
                'r.capacity_release_reason', 'r.created_at',
                'e.slug as event_slug', 'e.title_en as event_title_en', 'e.title_ar as event_title_ar',
                'e.starts_at', 'e.ends_at',
                't.name_en as ticket_name_en', 't.name_ar as ticket_name_ar',
                'o.order_number', 'o.customer_name', 'o.customer_email',
                'd.full_name', 'd.email', 'd.mobile',
                'gt.ticket_number',
                DB::raw("CASE
                    WHEN gt.id IS NULL THEN 'not_issued'
                    WHEN a.checked_in_at IS NOT NULL OR a.qr_status = 'used' THEN 'used'
                    WHEN a.qr_status = 'revoked' THEN 'revoked'
                    ELSE 'active'
                END as ticket_status")
            )
            ->where('r.id', $registrationId)
            ->first();

        if (!$registration) return null;

        return [
            'id' => $registration->id,
            'registration_number' => $registration->registration_number,
            'registration_status' => $registration->registration_status,
            'payment_status' => $registration->payment_status,
            'selected_currency' => $registration->selected_currency,
            'selected_price' => $registration->selected_price,
            'payment_reference' => $registration->payment_reference,
            'payment_method' => $registration->payment_method ?? null,
            'payment_proof_url' => $registration->payment_proof_url,
            'reservation_expires_at' => $registration->reservation_expires_at,
            'capacity_reservation_status' => $registration->capacity_reservation_status,
            'capacity_released_at' => $registration->capacity_released_at,
            'capacity_release_reason' => $registration->capacity_release_reason,
            'created_at' => $registration->created_at,
            'full_name' => $registration->full_name,
            'email' => $registration->email,
            'mobile' => $registration->mobile,
            'event_slug' => $registration->event_slug,
            'event_title_en' => $registration->event_title_en,
            'event_title_ar' => $registration->event_title_ar,
            'starts_at' => $registration->starts_at,
            'ends_at' => $registration->ends_at,
            'ticket_name_en' => $registration->ticket_name_en,
            'ticket_name_ar' => $registration->ticket_name_ar,
            'ticket_number' => $registration->ticket_number,
            'ticket_status' => $registration->ticket_status,
            'order_number' => $registration->order_number,
            'customer_name' => $registration->customer_name,
            'customer_email' => $registration->customer_email,
            'registrationNumber' => $registration->registration_number,
            'status' => $registration->registration_status,
            'paymentStatus' => $registration->payment_status,
            'price' => $registration->selected_price,
            'currency' => $registration->selected_currency,
            'createdAt' => $registration->created_at,
            'event' => [
                'slug' => $registration->event_slug,
                'titleEn' => $registration->event_title_en,
                'titleAr' => $registration->event_title_ar,
                'startsAt' => $registration->starts_at,
                'endsAt' => $registration->ends_at,
            ],
            'ticket' => [
                'nameEn' => $registration->ticket_name_en,
                'nameAr' => $registration->ticket_name_ar,
            ],
            'customerName' => $registration->customer_name,
            'customerEmail' => $registration->customer_email,
            'ticketNumber' => $registration->ticket_number,
        ];
    }

    public function getRegistration(Request $request, $reference)
    {
        $registration = DB::table('registrations as r')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as t', 't.id', '=', 'r.ticket_type_id')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->leftJoin('public_checkout_sessions as pcs', 'pcs.registration_id', '=', 'r.id')
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->select(
                'r.id', 'r.registration_number', 'r.registration_status', 'r.payment_status',
                'r.selected_currency', 'r.selected_price', 'r.created_at',
                'e.slug as event_slug', 'e.title_en as event_title_en', 'e.title_ar as event_title_ar', 'e.starts_at', 'e.ends_at',
                't.name_en as ticket_name_en', 't.name_ar as ticket_name_ar',
                'o.order_number', 'o.customer_name', 'o.customer_email',
                'd.user_id',
                'pcs.confirmation_token_hash', 'pcs.confirmation_token_expires_at',
                'gt.ticket_number', 'gt.qr_token',
                DB::raw("CASE
                    WHEN gt.id IS NULL THEN 'not_issued'
                    WHEN a.checked_in_at IS NOT NULL OR a.qr_status = 'used' THEN 'used'
                    WHEN a.qr_status = 'revoked' THEN 'revoked'
                    ELSE 'active'
                END as ticket_status")
            )
            ->where('r.registration_number', $reference)
            ->first();

        if (!$registration) {
            return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        }

        $authenticatedOwner = auth('api')->check()
            && (int)($registration->user_id ?? 0) === (int)auth('api')->id();

        if (!$authenticatedOwner) {
            $token = (string)($request->query('token') ?: $request->cookie($this->confirmationCookieName($reference)) ?: '');
            $hasValidToken = $registration->confirmation_token_hash
                && $token !== ''
                && hash_equals($registration->confirmation_token_hash, $this->tokenHash($token))
                && (!$registration->confirmation_token_expires_at || Carbon::parse($registration->confirmation_token_expires_at)->isFuture());

            if (!$hasValidToken) {
                return response()->json(['success' => false, 'message' => 'Confirmation access denied'], 403);
            }
        }

        DB::table('public_checkout_sessions')
            ->where('registration_id', $registration->id)
            ->update(['confirmed_at' => DB::raw('COALESCE(confirmed_at, NOW())')]);

        $summary = $this->registrationSummary($registration->id);

        $response = response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'registration' => $summary,
                'registrationNumber' => $registration->registration_number,
                'status' => $registration->registration_status,
                'paymentStatus' => $registration->payment_status,
                'price' => $registration->selected_price,
                'currency' => $registration->selected_currency,
                'createdAt' => $registration->created_at,
                'event' => [
                    'slug' => $registration->event_slug,
                    'titleEn' => $registration->event_title_en,
                    'titleAr' => $registration->event_title_ar,
                    'startsAt' => $registration->starts_at,
                    'endsAt' => $registration->ends_at
                ],
                'ticket' => [
                    'nameEn' => $registration->ticket_name_en,
                    'nameAr' => $registration->ticket_name_ar,
                ],
                'customerName' => $registration->customer_name,
                'customerEmail' => $registration->customer_email,
                'ticketNumber' => $registration->ticket_number,
            ]
        ]);

        if (!$authenticatedOwner && (string)$request->query('token') !== '') {
            $response->cookie($this->confirmationCookieName($reference), (string)$request->query('token'), 15, null, null, false, true, false, 'lax');
        }

        return $response;
    }

    public function checkout(Request $request, $slug)
    {
        $validated = tap(\Illuminate\Support\Facades\Validator::make($request->all(), [
            'idempotencyKey' => 'required|string',
            'ticketTypeId' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'email' => 'required|email',
            'fullName' => 'required|string|min:2',
            'mobile' => 'required|string',
            'countryCode' => 'required|string',
            'countryName' => 'required|string',
            'nationality' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'specialty' => 'nullable|string',
            'preferredLanguage' => 'nullable|string',
            'paymentReference' => 'nullable|string',
            'paymentMethod' => 'nullable|string|max:100',
            'paymentProofUrl' => 'nullable|string',
        ]), function ($validator) {
            if ($validator->fails()) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'details' => ['formErrors' => [], 'fieldErrors' => $validator->errors()]
                ], 400));
            }
        })->validated();

        $input = array_merge($validated, [
            'address' => $validated['address'] ?? '',
            'paymentReference' => $validated['paymentReference'] ?? null,
            'paymentMethod' => $validated['paymentMethod'] ?? null,
            'paymentProofUrl' => $validated['paymentProofUrl'] ?? null,
            'nationality' => $validated['nationality'] ?? '',
            'city' => $validated['city'] ?? '',
            'specialty' => $validated['specialty'] ?? '',
            'preferredLanguage' => $validated['preferredLanguage'] ?? '',
        ]);

        $hashData = $input;
        $hashData['slug'] = $slug;
        $hash = $this->payloadHash($hashData);

        $existingSession = DB::table('public_checkout_sessions')
            ->where('session_key', $input['idempotencyKey'])
            ->where('status', 'completed')
            ->whereNotNull('registration_id')
            ->first();

        if ($existingSession) {
            if ($existingSession->payload_hash !== $hash) {
                return response()->json(['success' => false, 'message' => 'Idempotency key already used with different checkout data'], 409);
            }
            $newToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            DB::table('public_checkout_sessions')
                ->where('session_key', $input['idempotencyKey'])
                ->update([
                    'confirmation_token_hash' => $this->tokenHash($newToken),
                    'confirmation_token_expires_at' => now()->addDays(7),
                ]);
            return response()->json([
                'success' => true,
                'message' => 'Checkout already completed',
                'data' => [
                    'registration' => $this->registrationSummary($existingSession->registration_id),
                    'confirmationToken' => $newToken,
                    'confirmationExpiresAt' => Carbon::parse($existingSession->confirmation_token_expires_at)->addDays(7)->toDateTimeString(),
                    'repeated' => true,
                ]
            ]);
        }

        try {
            $created = DB::transaction(function () use ($slug, $input, $hash, $request) {
                $event = DB::table('events')->where('slug', $slug)->where('status', 'published')->lockForUpdate()->first();
                if (!$event) {
                    throw new \Exception(json_encode(['status' => 404, 'message' => 'Event not found']));
                }

                $authUser = Auth::guard('api')->setRequest($request)->user();
                $ownerUserId = $authUser ? (int) $authUser->id : null;
                $policy = $this->normalizeEventPolicy($event);
                if (!$policy->publicRegistrationEnabled) {
                    throw new \Exception(json_encode(['status' => 409, 'message' => 'Public registration is disabled for this event', 'details' => ['state' => 'disabled']]));
                }
                if ($policy->access === 'login_required' && !$ownerUserId) {
                    throw new \Exception(json_encode(['status' => 401, 'message' => 'Login is required for this event registration', 'details' => ['state' => 'login_required']]));
                }
                if ($input['quantity'] > $policy->maxTicketsPerCheckout) {
                    throw new \Exception(json_encode(['status' => 400, 'message' => "Maximum tickets per checkout is {$policy->maxTicketsPerCheckout}", 'details' => ['maxTicketsPerCheckout' => $policy->maxTicketsPerCheckout]]));
                }

                $ticket = DB::table('ticket_types')
                    ->where('id', $input['ticketTypeId'])
                    ->where('event_id', $event->id)
                    ->where('is_active', 1)
                    ->lockForUpdate()
                    ->first();

                if (!$ticket) {
                    throw new \Exception(json_encode(['status' => 404, 'message' => 'Ticket type is not available']));
                }

                $this->releaseExpiredReservations($event->id, $ticket->id);
                $counts = $this->countActiveReservations($event->id, $ticket->id);

                $ticketSoldOut = $ticket->quota && ($counts['ticketReservedCount'] + $input['quantity'] > $ticket->quota);
                $eventSoldOut = $event->max_attendees && ($counts['eventReservedCount'] + $input['quantity'] > $event->max_attendees);

                $state = $this->eventState($event, $ticketSoldOut || $eventSoldOut);
                if ($state !== 'open') {
                    throw new \Exception(json_encode(['status' => 409, 'message' => "Registration is $state", 'details' => ['state' => $state]]));
                }

                $newConfirmationToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                DB::table('public_checkout_sessions')->insert([
                    'session_key' => $input['idempotencyKey'],
                    'payload_hash' => $hash,
                    'confirmation_token_hash' => $this->tokenHash($newConfirmationToken),
                    'confirmation_token_expires_at' => now()->addDays(7),
                    'event_id' => $event->id,
                    'ticket_type_id' => $ticket->id,
                    'customer_email' => $input['email'],
                    'status' => 'pending',
                    'expires_at' => now()->addMinutes(30),
                ]);

                if ($ownerUserId) {
                    $doctor = DB::table('doctors')->where('user_id', $ownerUserId)->lockForUpdate()->first();
                } else {
                    $doctor = DB::table('doctors')->where('email', $input['email'])->lockForUpdate()->first();
                }

                $doctorUpdates = [
                    'full_name' => $input['fullName'],
                    'mobile' => $input['mobile'],
                    'address' => $input['address'],
                    'country_code' => $input['countryCode'],
                    'country_name' => $input['countryName'],
                    'city' => $input['city'],
                    'specialty' => $input['specialty'],
                    'nationality' => $input['nationality'],
                    'preferred_language' => $input['preferredLanguage'],
                    'updated_at' => now(),
                ];

                if ($ownerUserId) {
                    $doctorUpdates['user_id'] = $ownerUserId;
                }

                if ($doctor) {
                    $doctorId = $doctor->id;
                    DB::table('doctors')->where('id', $doctorId)->update($doctorUpdates);
                } else {
                    $doctorId = DB::table('doctors')->insertGetId([
                        'user_id' => $ownerUserId,
                        'full_name' => $input['fullName'],
                        'mobile' => $input['mobile'],
                        'email' => $input['email'],
                        'address' => $input['address'],
                        'country_code' => $input['countryCode'],
                        'country_name' => $input['countryName'],
                        'city' => $input['city'],
                        'specialty' => $input['specialty'],
                        'nationality' => $input['nationality'],
                        'preferred_language' => $input['preferredLanguage'],
                    ]);
                }

                $currency = $this->isEgyptianCountry($input['countryCode'], $input['countryName'], $input['nationality']) ? 'EGP' : 'USD';
                $pricePeriod = $this->currentPricePeriod($ticket->id, $currency);
                if (!$pricePeriod) {
                    throw new \Exception(json_encode(['status' => 409, 'message' => 'No active price period is available for this ticket']));
                }

                $isFree = $pricePeriod->selected_price <= 0;
                if (!$isFree && !$policy->manualPaymentEnabled) {
                    throw new \Exception(json_encode(['status' => 409, 'message' => 'Manual payment is not enabled for this event', 'details' => ['state' => 'payment_unavailable']]));
                }

                $paymentSubmitted = (bool)($input['paymentReference'] || $input['paymentProofUrl']);
                $initialState = $this->getCheckoutInitialState($isFree, $paymentSubmitted, $policy->approvalMode);

                $orderId = DB::table('orders')->insertGetId([
                    'customer_id' => $ownerUserId,
                    'event_id' => $event->id,
                    'order_number' => 'ORD-' . strtoupper(base_convert(time(), 10, 36) . '-' . bin2hex(random_bytes(3))),
                    'status' => $initialState->orderStatus,
                    'subtotal' => $pricePeriod->selected_price,
                    'grand_total' => $pricePeriod->selected_price,
                    'currency' => $pricePeriod->selected_currency,
                    'customer_name' => $input['fullName'],
                    'customer_email' => $input['email'],
                    'customer_phone' => $input['mobile'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $registrationPayload = [
                    'registration_number' => 'REG-' . strtoupper(base_convert(time(), 10, 36) . '-' . bin2hex(random_bytes(3))),
                    'doctor_id' => $doctorId,
                    'event_id' => $event->id,
                    'ticket_type_id' => $ticket->id,
                    'order_id' => $orderId,
                    'source' => 'online',
                    'registration_status' => $initialState->registrationStatus,
                    'payment_status' => $initialState->paymentStatus,
                    'selected_currency' => $pricePeriod->selected_currency,
                    'selected_price' => $pricePeriod->selected_price,
                    'selected_price_period_id' => $pricePeriod->id,
                    'payment_reference' => $input['paymentReference'],
                    'payment_proof_url' => $input['paymentProofUrl'],
                    'reservation_expires_at' => $isFree ? null : DB::raw($this->reservationExpirySql($policy->capacityHoldHoursOverride)),
                    'capacity_reservation_status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($this->hasPaymentMethodColumn()) {
                    $registrationPayload['payment_method'] = $input['paymentMethod'];
                }

                $regId = DB::table('registrations')->insertGetId($registrationPayload);

                if ($initialState->shouldIssueTicket) {
                    $this->issueTicket($regId);
                }

                DB::table('public_checkout_sessions')
                    ->where('session_key', $input['idempotencyKey'])
                    ->update([
                        'registration_id' => $regId,
                        'status' => 'completed'
                    ]);

                return (object)[
                    'registrationId' => $regId,
                    'currency' => $pricePeriod->selected_currency,
                    'price' => $pricePeriod->selected_price,
                    'isFree' => $isFree,
                    'confirmationToken' => $newConfirmationToken,
                ];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] === 1062) { // ER_DUP_ENTRY
                $duplicate = DB::table('public_checkout_sessions')
                    ->where('session_key', $input['idempotencyKey'])
                    ->first();
                if ($duplicate && $duplicate->payload_hash !== $hash) {
                    return response()->json(['success' => false, 'message' => 'Idempotency key already used with different checkout data'], 409);
                }
                if ($duplicate && $duplicate->registration_id) {
                    $newToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    DB::table('public_checkout_sessions')
                        ->where('session_key', $input['idempotencyKey'])
                        ->update([
                            'confirmation_token_hash' => $this->tokenHash($newToken),
                            'confirmation_token_expires_at' => now()->addDays(7),
                        ]);
                    return response()->json([
                        'success' => true,
                        'message' => 'Checkout already completed',
                        'data' => [
                            'registration' => $this->registrationSummary($duplicate->registration_id),
                            'confirmationToken' => $newToken,
                            'confirmationExpiresInSeconds' => 604800,
                            'repeated' => true,
                        ]
                    ]);
                }
                return response()->json(['success' => false, 'message' => 'Checkout request is already being processed'], 409);
            }
            return response()->json(['success' => false, 'message' => 'Checkout failed', 'details' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            $err = json_decode($e->getMessage(), true);
            if ($err && isset($err['status'])) {
                return response()->json([
                    'success' => false,
                    'message' => $err['message'],
                    'details' => $err['details'] ?? null
                ], $err['status']);
            }
            return response()->json(['success' => false, 'message' => 'Checkout failed', 'details' => $e->getMessage()], 500);
        }

        $msg = $created->isFree ? 'Free registration confirmed and ticket issued' : 'Registration created. Payment is pending verification.';
        app(UserNotificationService::class)->notifyRegistrationUser(
            (int) $created->registrationId,
            $created->isFree ? 'ticket_available' : 'registration_created',
            $created->isFree ? 'Ticket / QR Available' : 'Registration Created',
            $created->isFree ? 'Your registration is confirmed and your ticket is ready.' : 'Your registration was created. Payment is pending verification.',
            [
                'title_ar' => $created->isFree ? 'التذكرة ورمز QR جاهزان' : 'تم إنشاء التسجيل',
                'message_ar' => $created->isFree ? 'تم تأكيد تسجيلك والتذكرة جاهزة.' : 'تم إنشاء تسجيلك والدفع بانتظار المراجعة.',
            ]
        );
        return response()->json([
            'success' => true,
            'message' => $msg,
            'data' => [
                'registration' => $this->registrationSummary($created->registrationId),
                'bankAccount' => $this->paymentMethods($created->currency)[0] ?? null,
                'paymentMethods' => $this->paymentMethods($created->currency),
                'checkout' => [
                    'currency' => $created->currency,
                    'price' => $created->price,
                    'isFree' => $created->isFree
                ],
                'confirmationToken' => $created->confirmationToken,
                'confirmationExpiresInSeconds' => 604800,
            ]
        ]);
    }
}
