<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    private function generateBookingNumber()
    {
        $timestamp = strtoupper(base_convert(time(), 10, 36));
        $random = strtoupper(substr(base_convert(mt_rand(), 10, 36), 0, 5));
        return "CONF-{$timestamp}-{$random}";
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'bookingType' => 'required|in:single,recurring,annual,general',
            'fullName' => 'required|string|max:180',
            'email' => 'required|email|max:180',
            'countryCode' => 'required|string|max:12',
            'phone' => 'required|string|max:40',
            'preferredContactMethod' => 'required|in:phone,email,whatsapp',
            'jobTitle' => 'required|string|max:180',
            'organization' => 'nullable|string|max:180',
            'eventName' => 'nullable|string|max:220',
            'eventType' => 'nullable|string|max:80',
            'eventDate' => 'nullable|string|max:80',
            'isDateFlexible' => 'required|boolean',
            'country' => 'nullable|string|max:120',
            'location' => 'nullable|string|max:220',
            'venueStatus' => 'required|in:known,not_decided',
            'expectedAttendance' => 'nullable|string|max:80',
            'budgetRange' => 'nullable|string|max:120',
            'services' => 'nullable|array',
            'objectives' => 'nullable|string',
            'eventBrief' => 'required|string|min:20|max:1200',
            'additionalRequirements' => 'nullable|string',
            'privacyConsent' => 'required|boolean',
            'communicationConsent' => 'required|boolean',
            'language' => 'required|in:en,ar'
        ]);

        $bookingNumber = $this->generateBookingNumber();

        DB::table('event_brief_requests')->insert([
            'reference_number' => $bookingNumber,
            'request_type' => $validated['bookingType'],
            'full_name' => $validated['fullName'],
            'email' => $validated['email'],
            'country_code' => $validated['countryCode'],
            'phone' => $validated['phone'],
            'preferred_contact_method' => $validated['preferredContactMethod'],
            'job_title' => $validated['jobTitle'],
            'organization' => $validated['organization'] ?? null,
            'event_name' => $validated['eventName'] ?? null,
            'event_type' => $validated['eventType'] ?? null,
            'event_date' => $validated['eventDate'] ?? null,
            'is_date_flexible' => $validated['isDateFlexible'],
            'country' => $validated['country'] ?? null,
            'location' => $validated['location'] ?? null,
            'venue_status' => $validated['venueStatus'],
            'expected_attendance' => $validated['expectedAttendance'] ?? null,
            'budget_range' => $validated['budgetRange'] ?? null,
            'services_json' => json_encode($validated['services'] ?? []),
            'objectives' => $validated['objectives'] ?? null,
            'event_brief' => $validated['eventBrief'],
            'additional_requirements' => $validated['additionalRequirements'] ?? null,
            'privacy_consent' => $validated['privacyConsent'],
            'communication_consent' => $validated['communicationConsent'],
            'language' => $validated['language']
        ]);

        try {
            DB::table('admin_notifications')->insert([
                'title' => "New event brief {$bookingNumber}",
                'body' => "{$validated['fullName']} submitted a {$validated['bookingType']} event brief.",
                'type' => 'system',
                'severity' => 'info',
                'target_url' => '/admin/settings',
                'created_at' => now()
            ]);
        } catch (\Exception $e) {}

        return response()->json([
            'success' => true,
            'message' => 'Event brief received successfully',
            'bookingNumber' => $bookingNumber,
            'referenceNumber' => $bookingNumber,
            'createdAt' => now()->toIso8601String()
        ]);
    }
}
