<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DoctorController extends Controller
{
    public function index(Request $request)
    {
        $search = trim($request->query('search', ''));

        $query = DB::table('doctors')
            ->select(
                'id',
                'full_name',
                'mobile',
                'email',
                'country_code',
                'country_name',
                'city',
                'specialty',
                'nationality',
                'preferred_language',
                'status',
                'created_at'
            )
            ->orderBy('created_at', 'desc')
            ->limit(250);

        if ($search !== '') {
            $searchTerm = '%' . $search . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('full_name', 'LIKE', $searchTerm)
                  ->orWhere('email', 'LIKE', $searchTerm)
                  ->orWhere('mobile', 'LIKE', $searchTerm)
                  ->orWhere('specialty', 'LIKE', $searchTerm);
            });
        }

        $doctors = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $doctors
        ]);
    }

    public function lookupProfile(Request $request)
    {
        $identity = trim($request->query('identity', ''));
        if (strlen($identity) < 3) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email, mobile, or registration number is required'
            ], 400);
        }

        // Apply event scope if the user is an event-scoped employee
        $user = $request->user();

        // Subquery or join for registrations
        // Note: we just want the doctor details and their total counts across all allowed events
        // Actually, Node didn't apply event scoping for doctor lookup. Let's match Node first, but wait, the instructions said:
        // "Review all Phase G endpoints against: event_staff_assignments ... Verify employees cannot access events outside their assigned scope."
        // We will apply this to the history.

        $doctorQuery = DB::table('doctors as d')
            ->select('d.*')
            ->selectRaw('COUNT(DISTINCT r.id) AS registrations_count')
            ->selectRaw('COUNT(DISTINCT gt.id) AS tickets_count')
            ->selectRaw('COUNT(DISTINCT c.id) AS certificates_count')
            ->leftJoin('registrations as r', function($join) use ($user) {
                $join->on('r.doctor_id', '=', 'd.id');
                if ($user && $user->role_code === 'employee') {
                    $join->whereIn('r.event_id', function ($query) use ($user) {
                        $query->select('event_id')
                            ->from('event_staff_assignments')
                            ->where('user_id', $user->id);
                    });
                }
            })
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->leftJoin('certificates as c', 'c.attendee_id', '=', 'a.id')
            ->where(function ($q) use ($identity) {
                $q->where('d.email', $identity)
                  ->orWhere('d.mobile', $identity)
                  ->orWhere('r.registration_number', $identity);
            })
            ->groupBy('d.id')
            ->limit(1);

        $doctor = $doctorQuery->first();

        if (!$doctor) {
            return response()->json([
                'status' => 'error',
                'message' => 'Doctor profile not found'
            ], 404);
        }

        $historyQuery = DB::table('registrations as r')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'r.ticket_type_id')
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->leftJoin('certificates as c', 'c.attendee_id', '=', 'a.id')
            ->where('r.doctor_id', $doctor->id)
            ->select(
                'r.id',
                'r.registration_number',
                'r.registration_status',
                'r.payment_status',
                'r.selected_currency',
                'r.selected_price',
                'r.payment_reference',
                'r.payment_proof_url',
                'r.created_at',
                'e.title_en AS event_title_en',
                'e.title_ar AS event_title_ar',
                'e.starts_at',
                'e.ends_at',
                'tt.name_en AS ticket_name_en',
                'tt.name_ar AS ticket_name_ar',
                'gt.ticket_number',
                'gt.qr_token',
                'gt.pdf_url AS ticket_pdf_url',
                'c.certificate_number',
                'c.file_url AS certificate_file_url',
                'c.status AS certificate_status'
            )
            ->orderBy('r.created_at', 'desc');

        if ($user && $user->role_code === 'employee') {
            $historyQuery->whereIn('r.event_id', function ($query) use ($user) {
                $query->select('event_id')
                    ->from('event_staff_assignments')
                    ->where('user_id', $user->id);
            });
        }

        $history = $historyQuery->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'doctor' => $doctor,
                'history' => $history
            ]
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        $doctorQuery = DB::table('doctors as d')
            ->select('d.*')
            ->selectRaw('COUNT(DISTINCT r.id) AS registrations_count')
            ->selectRaw('COUNT(DISTINCT gt.id) AS tickets_count')
            ->selectRaw('COUNT(DISTINCT c.id) AS certificates_count')
            ->leftJoin('registrations as r', function($join) use ($user) {
                $join->on('r.doctor_id', '=', 'd.id');
                if ($user && $user->role_code === 'employee') {
                    $join->whereIn('r.event_id', function ($query) use ($user) {
                        $query->select('event_id')
                            ->from('event_staff_assignments')
                            ->where('user_id', $user->id);
                    });
                }
            })
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->leftJoin('certificates as c', 'c.attendee_id', '=', 'a.id')
            ->where('d.id', $id)
            ->groupBy('d.id')
            ->limit(1);

        $doctor = $doctorQuery->first();

        if (!$doctor) {
            return response()->json([
                'status' => 'error',
                'message' => 'Doctor not found'
            ], 404);
        }

        $historyQuery = DB::table('registrations as r')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_types as tt', 'tt.id', '=', 'r.ticket_type_id')
            ->leftJoin('generated_tickets as gt', 'gt.registration_id', '=', 'r.id')
            ->leftJoin('attendees as a', 'a.id', '=', 'gt.attendee_id')
            ->leftJoin('certificates as c', 'c.attendee_id', '=', 'a.id')
            ->where('r.doctor_id', $doctor->id)
            ->select(
                'r.id',
                'r.registration_number',
                'r.registration_status',
                'r.payment_status',
                'r.selected_currency',
                'r.selected_price',
                'e.title_en AS event_title_en',
                'e.title_ar AS event_title_ar',
                'e.starts_at',
                'e.ends_at',
                'tt.name_en AS ticket_name_en',
                'tt.name_ar AS ticket_name_ar',
                'gt.ticket_number',
                'gt.pdf_url AS ticket_pdf_url',
                'c.certificate_number',
                'c.file_url AS certificate_file_url',
                'c.status AS certificate_status'
            )
            ->orderBy('r.created_at', 'desc');

        if ($user && $user->role_code === 'employee') {
            $historyQuery->whereIn('r.event_id', function ($query) use ($user) {
                $query->select('event_id')
                    ->from('event_staff_assignments')
                    ->where('user_id', $user->id);
            });
        }

        $history = $historyQuery->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'doctor' => $doctor,
                'history' => $history
            ]
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fullName' => 'required|string|min:2',
            'mobile' => 'required|string|min:7',
            'email' => 'required|email',
            'address' => 'nullable|string',
            'countryCode' => 'required|string|min:2|max:2',
            'countryName' => 'required|string|min:2',
            'city' => 'required|string|min:2',
            'specialty' => 'required|string|min:2',
            'nationality' => 'required|string|min:2',
            'preferredLanguage' => 'nullable|in:ar,en',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $data = $validator->validated();
        $preferredLanguage = $data['preferredLanguage'] ?? 'en';
        $countryCode = strtoupper($data['countryCode']);

        $existing = DB::table('doctors')->where('email', $data['email'])->first();
        if ($existing) {
            return response()->json([
                'status' => 'error',
                'message' => 'Doctor email already exists',
                'data' => ['id' => $existing->id]
            ], 409);
        }

        $insertData = [
            'full_name' => $data['fullName'],
            'mobile' => $data['mobile'],
            'email' => $data['email'],
            'address' => $data['address'] ?? null,
            'country_code' => $countryCode,
            'country_name' => $data['countryName'],
            'city' => $data['city'],
            'specialty' => $data['specialty'],
            'nationality' => $data['nationality'],
            'preferred_language' => $preferredLanguage,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $id = DB::table('doctors')->insertGetId($insertData);

        return response()->json([
            'status' => 'success',
            'message' => 'Doctor profile created',
            'data' => array_merge(['id' => $id], $data)
        ]);
    }
}
