<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class KioskController extends Controller
{
    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'eventId' => 'nullable|integer|min:1',
            'searchType' => 'required|in:email,mobile,registration_number',
            'searchValue' => 'required|string|min:2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $data = $validator->validated();
        $eventId = isset($data['eventId']) ? (int) $data['eventId'] : 0;
        $searchType = $data['searchType'];
        $searchValue = trim($data['searchValue']);

        $user = $request->user();

        $query = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'r.ticket_type_id')
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->leftJoin('event_cards as ec', 'ec.attendee_id', '=', 'a.id')
            ->select(
                'r.id',
                'r.registration_number',
                'r.registration_status',
                'r.payment_status',
                'r.selected_currency',
                'r.selected_price',
                'd.full_name AS doctor_name',
                'd.mobile AS doctor_mobile',
                'd.email AS doctor_email',
                'd.specialty',
                'd.nationality',
                'e.id AS event_id',
                'e.title_en AS event_title_en',
                'e.title_ar AS event_title_ar',
                'e.starts_at',
                'e.ends_at',
                'tt.name_en AS ticket_name_en',
                'tt.name_ar AS ticket_name_ar',
                'a.attendee_number',
                'a.checked_in_at',
                'gt.ticket_number',
                'gt.qr_token',
                'gt.pdf_url AS ticket_pdf_url',
                'ec.card_number',
                'ec.file_url AS event_card_url'
            );

        if ($searchType === 'email') {
            $query->where('d.email', $searchValue);
        } elseif ($searchType === 'mobile') {
            $query->where('d.mobile', $searchValue);
        } else {
            $query->where('r.registration_number', $searchValue);
        }

        if ($eventId !== 0) {
            $query->where('r.event_id', $eventId);
        }

        // Scope Check for Employee
        if ($user && $user->role_code === 'employee') {
            $query->whereIn('r.event_id', function ($q) use ($user) {
                $q->select('event_id')
                  ->from('event_staff_assignments')
                  ->where('user_id', $user->id);
            });
        }

        $registration = $query->orderBy('r.created_at', 'desc')->first();

        DB::table('kiosk_search_logs')->insert([
            'event_id' => $eventId !== 0 ? $eventId : ($registration ? $registration->event_id : null),
            'search_type' => $searchType,
            'search_value' => $searchValue,
            'result_status' => $registration ? 'found' : 'not_found',
            'matched_registration_id' => $registration ? $registration->id : null,
            'created_at' => now(),
        ]);

        if (!$registration) {
            return response()->json([
                'status' => 'error',
                'message' => 'Registration not found'
            ], 404);
        }

        if ($registration->payment_status !== 'approved' && $registration->payment_status !== 'free') {
            // Note: In Node it only checked !== 'approved', but wait, in Node 'free' is not a separate thing?
            // Actually Node checks `registration.payment_status !== 'approved'`. Let's strictly follow Node.
            if ($registration->payment_status !== 'approved') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment is not approved yet',
                    'data' => ['registration' => $registration]
                ], 409);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Registration found',
            'data' => $registration
        ]);
    }
}
