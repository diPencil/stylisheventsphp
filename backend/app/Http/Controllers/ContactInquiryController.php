<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Exception;

class ContactInquiryController extends Controller
{
    private $inquiryTypes = ['general', 'event_planning', 'technical_support', 'partnership', 'existing_booking', 'other'];
    private $statuses = ['new', 'in_progress', 'waiting_for_customer', 'resolved', 'closed'];
    private $contactMethods = ['email', 'phone', 'whatsapp'];

    private function mapInquiry($row)
    {
        return [
            'id' => (int) $row->id,
            'referenceCode' => $row->reference_code,
            'fullName' => $row->full_name,
            'email' => $row->email,
            'phoneCountryCode' => $row->phone_country_code ?? '',
            'phoneNumber' => $row->phone_number ?? '',
            'company' => $row->company ?? '',
            'inquiryType' => $row->inquiry_type,
            'subject' => $row->subject,
            'message' => $row->message,
            'preferredContactMethod' => $row->preferred_contact_method ?? 'email',
            'eventDate' => $row->event_date ?? '',
            'eventCity' => $row->event_city ?? '',
            'expectedAttendees' => $row->expected_attendees === null ? '' : (int) $row->expected_attendees,
            'status' => $row->status,
            'adminNotes' => $row->admin_notes ?? '',
            'assignedTo' => $row->assigned_to ?? null,
            'sourcePage' => $row->source_page ?? '',
            'consentAcceptedAt' => $row->consent_accepted_at ?? '',
            'consentVersion' => $row->consent_version ?? '',
            'createdAt' => $row->created_at ?? '',
            'updatedAt' => $row->updated_at ?? '',
            'resolvedAt' => $row->resolved_at ?? '',
        ];
    }

    public function store(Request $request)
    {
        // Public endpoint
        $validator = Validator::make($request->all(), [
            'fullName' => 'required|string|min:2|max:180',
            'email' => 'required|email|max:180',
            'phoneCountryCode' => 'nullable|string|max:12',
            'phoneNumber' => 'nullable|string|max:40',
            'company' => 'nullable|string|max:180',
            'inquiryType' => 'required|in:' . implode(',', $this->inquiryTypes),
            'subject' => 'required|string|min:3|max:220',
            'message' => 'required|string|min:20|max:2000',
            'preferredContactMethod' => 'nullable|in:' . implode(',', $this->contactMethods),
            'eventDate' => 'nullable|string|max:40',
            'eventCity' => 'nullable|string|max:180',
            'expectedAttendees' => 'nullable|integer|min:1|max:1000000',
            'consentAccepted' => 'required|accepted',
            'consentVersion' => 'nullable|string|max:80',
            'sourcePage' => 'nullable|string|max:120',
            'website' => 'nullable|string|max:0',
            'submittedAfterMs' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $data = $validator->validated();

        if (isset($data['website']) && strlen($data['website']) > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => ['website' => ['Invalid submission']]
            ], 400);
        }

        if (isset($data['submittedAfterMs']) && $data['submittedAfterMs'] < 1800) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => ['submittedAfterMs' => ['Submission was too fast']]
            ], 400);
        }

        $recent = DB::table('contact_inquiries')
            ->where('email', $data['email'])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        if ($recent >= 3) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many requests'
            ], 429);
        }

        $eventDate = null;
        if (!empty($data['eventDate'])) {
            try {
                $eventDate = Carbon::parse($data['eventDate'])->format('Y-m-d');
            } catch (Exception $e) {
                // Ignore parsing errors, let it be null or fail validator
                $eventDate = null;
            }
        }

        $expectedAttendees = $data['expectedAttendees'] ?? null;

        $referenceCode = '';
        $id = null;

        $year = now()->year;

        DB::transaction(function () use (&$referenceCode, &$id, $year, $data, $eventDate, $expectedAttendees) {
            $counterId = DB::table('contact_inquiries_reference_counter')->insertGetId([]);
            $referenceCode = 'INQ-' . $year . '-' . str_pad($counterId, 6, '0', STR_PAD_LEFT);

            $id = DB::table('contact_inquiries')->insertGetId([
                'reference_code' => $referenceCode,
                'full_name' => $data['fullName'],
                'email' => $data['email'],
                'phone_country_code' => $data['phoneCountryCode'] ?? null,
                'phone_number' => $data['phoneNumber'] ?? null,
                'company' => $data['company'] ?? null,
                'inquiry_type' => $data['inquiryType'],
                'subject' => $data['subject'],
                'message' => $data['message'],
                'preferred_contact_method' => $data['preferredContactMethod'] ?? 'email',
                'event_date' => $eventDate,
                'event_city' => $data['eventCity'] ?? null,
                'expected_attendees' => $expectedAttendees,
                'status' => 'new',
                'source_page' => $data['sourcePage'] ?? '/contact',
                'consent_accepted_at' => now(),
                'consent_version' => $data['consentVersion'] ?? 'contact-inquiry-v1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Insert admin notification (ignore failure)
        try {
            DB::table('admin_notifications')->insert([
                'title' => "New contact inquiry {$referenceCode}",
                'body' => "{$data['fullName']} sent a " . str_replace('_', ' ', $data['inquiryType']) . " inquiry.",
                'type' => 'system',
                'severity' => 'info',
                'target_url' => '/admin/contact-inquiries',
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Ignore
        }

        return response()->json([
            'success' => true,
            'message' => 'Inquiry received',
            'data' => [
                'referenceCode' => $referenceCode,
                'status' => 'new',
                'createdAt' => now()->toIso8601String(),
            ]
        ], 201);
    }

    public function index(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 10), 1), 50);
        $offset = max((int) $request->query('offset', 0), 0);
        $search = trim($request->query('search', ''));

        $status = $request->query('status');
        $status = in_array($status, $this->statuses) ? $status : '';

        $type = $request->query('type');
        $type = in_array($type, $this->inquiryTypes) ? $type : '';

        $date = trim($request->query('date', ''));

        $query = DB::table('contact_inquiries');

        if ($search !== '') {
            $searchLike = '%' . $search . '%';
            $query->where(function ($q) use ($searchLike) {
                $q->where('reference_code', 'LIKE', $searchLike)
                  ->orWhere('full_name', 'LIKE', $searchLike)
                  ->orWhere('email', 'LIKE', $searchLike)
                  ->orWhere('subject', 'LIKE', $searchLike)
                  ->orWhere('phone_number', 'LIKE', $searchLike);
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($type !== '') {
            $query->where('inquiry_type', $type);
        }

        if ($date !== '') {
            $query->whereDate('created_at', $date);
        }

        $total = $query->count();
        $rows = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit($limit)->offset($offset)->get();

        $summaryRows = DB::table('contact_inquiries')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->get();

        $summary = [];
        foreach ($summaryRows as $row) {
            $summary[$row->status] = (int) $row->total;
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'data' => $rows->map(function ($row) { return $this->mapInquiry($row); }),
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                ],
                'summary' => $summary,
            ]
        ]);
    }

    public function show($id)
    {
        $row = DB::table('contact_inquiries')->where('id', $id)->first();
        if (!$row) {
            return response()->json([
                'status' => 'error',
                'message' => 'Inquiry not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->mapInquiry($row)
        ]);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:' . implode(',', $this->statuses),
            'adminNotes' => 'nullable|string|max:4000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $data = $validator->validated();

        $current = DB::table('contact_inquiries')->where('id', $id)->first();
        if (!$current) {
            return response()->json([
                'status' => 'error',
                'message' => 'Inquiry not found'
            ], 404);
        }

        $nextStatus = $data['status'] ?? $current->status;

        $resolvedAt = $current->resolved_at;
        if (in_array($nextStatus, ['resolved', 'closed']) && !$current->resolved_at) {
            $resolvedAt = now();
        } elseif (!in_array($nextStatus, ['resolved', 'closed'])) {
            $resolvedAt = null;
        }

        DB::table('contact_inquiries')->where('id', $id)->update([
            'status' => $nextStatus,
            'admin_notes' => $data['adminNotes'] ?? $current->admin_notes,
            'resolved_at' => $resolvedAt,
            'updated_at' => now(),
        ]);

        try {
            DB::table('audit_logs')->insert([
                'user_id' => auth('api')->id(),
                'action' => 'contact_inquiries.update',
                'entity_type' => 'contact_inquiry',
                'entity_id' => (string) $current->id,
                'metadata_json' => json_encode(['status' => $nextStatus]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('user-agent'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit logging must not prevent an admin from updating an inquiry.
        }

        $updated = DB::table('contact_inquiries')->where('id', $id)->first();
        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry updated',
            'data' => $this->mapInquiry($updated)
        ]);
    }
}
