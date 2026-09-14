<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Mail\CertificateDeliveryMail;
use App\Services\PlatformMailer;
use App\Services\UserNotificationService;

class CertificateController extends Controller
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

    private function generateCertificateNumber()
    {
        return 'CERT-' . strtoupper(base_convert(now()->timestamp, 10, 36)) . '-' . strtoupper(Str::random(6));
    }

    private function generateCardNumber()
    {
        return 'CARD-' . strtoupper(base_convert(now()->timestamp, 10, 36)) . '-' . strtoupper(Str::random(6));
    }

    private function auditLog(Request $request, string $action, ?string $entityType = null, $entityId = null, array $metadata = [])
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'metadata_json' => json_encode($metadata),
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('user-agent'),
            'created_at' => now(),
        ]);
    }

    private function frontendUrl(string $path)
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Resolve the effective template for an event: an explicitly stored
     * template id first (must belong to the event), otherwise the event
     * default (is_default DESC, updated_at DESC).
     */
    private function resolveTemplate(int $eventId, $templateKeyOrId = null)
    {
        if ($templateKeyOrId !== null && ctype_digit((string) $templateKeyOrId)) {
            $explicit = DB::table('certificate_templates')
                ->where('id', (int) $templateKeyOrId)
                ->where('event_id', $eventId)
                ->first(['id', 'name', 'template_url', 'field_positions_json']);
            if ($explicit) return $explicit;
        }
        return DB::table('certificate_templates')
            ->where('event_id', $eventId)
            ->orderBy('is_default', 'desc')
            ->orderBy('updated_at', 'desc')
            ->first(['id', 'name', 'template_url', 'field_positions_json']);
    }

    public function getTemplates(Request $request)
    {
        $eventId = (int) $request->query('eventId', 0);
        $user = $request->user();

        if ($eventId !== 0 && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $query = DB::table('certificate_templates as ct')
            ->join('events as e', 'e.id', '=', 'ct.event_id')
            ->select(
                'ct.id',
                'ct.event_id',
                'ct.name',
                'ct.template_type',
                'ct.template_url',
                'ct.field_positions_json',
                'ct.is_default',
                'ct.is_active',
                'ct.created_at',
                'ct.updated_at'
            )
            ->orderBy('ct.is_default', 'desc')
            ->orderBy('ct.updated_at', 'desc');

        if ($eventId !== 0) {
            $query->where('ct.event_id', $eventId);
        }

        $this->applyEventScope($query, $user, 'ct.event_id');

        $rows = $query->get()->map(function ($row) {
            $row->is_default = (bool) $row->is_default;
            $row->is_active = (bool) $row->is_active;
            return $row;
        });

        return response()->json([
            'status' => 'success',
            'data' => $rows
        ]);
    }

    public function getDelivery(Request $request)
    {
        $eventId = (int) $request->query('eventId', 0);
        $limit = min(max((int) $request->query('limit', 500), 1), 1000);
        $offset = max((int) $request->query('offset', 0), 0);
        $search = trim((string) $request->query('search', ''));
        $includeMeta = filter_var($request->query('meta', false), FILTER_VALIDATE_BOOLEAN);
        $user = $request->user();

        if ($eventId !== 0 && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $query = DB::table('attendees as a')
            ->leftJoin('events as e', 'e.id', '=', 'a.event_id')
            ->leftJoin('ticket_types as tt', 'tt.id', '=', 'a.ticket_type_id')
            ->leftJoin('certificates as c', 'c.attendee_id', '=', 'a.id')
            ->leftJoin('event_cards as ec', 'ec.attendee_id', '=', 'a.id')
            ->select(
                'a.id AS attendee_id',
                'a.attendee_number',
                'a.full_name',
                'a.email',
                'a.qr_token',
                'a.checked_in_at',
                'a.certificate_issued_at',
                'e.id AS event_id',
                'e.title_en AS event_title_en',
                'e.title_ar AS event_title_ar',
                'e.starts_at AS event_starts_at',
                'tt.name_en AS ticket_name_en',
                'tt.name_ar AS ticket_name_ar',
                'c.id AS certificate_id',
                'c.certificate_number',
                'c.status AS certificate_status',
                'c.template_key AS certificate_template_key',
                'c.file_url AS certificate_file_url',
                'c.issued_at AS certificate_sent_at',
                'ec.id AS card_id',
                'ec.card_number',
                'ec.template_key AS card_template_key',
                'ec.file_url AS card_file_url',
                'ec.created_at AS card_sent_at'
            )
            ->orderBy('a.created_at', 'desc')
            ->limit(500);

        if ($eventId !== 0) {
            $query->where('a.event_id', $eventId);
        }

        $this->applyEventScope($query, $user, 'a.event_id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('a.full_name', 'like', $like)
                    ->orWhere('a.email', 'like', $like)
                    ->orWhere('a.attendee_number', 'like', $like)
                    ->orWhere('c.certificate_number', 'like', $like)
                    ->orWhere('tt.name_en', 'like', $like)
                    ->orWhere('tt.name_ar', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $query->offset($offset)->limit($limit);
        $rows = $query->get();

        // Attach the stored template (id + name) per issued certificate so the
        // admin UI can show which design each attendee received.
        $wantedIds = [];
        foreach ($rows as $row) {
            $key = $row->certificate_template_key ?? null;
            if ($key !== null && ctype_digit((string) $key)) {
                $wantedIds[(int) $key] = true;
            }
        }
        $templateNames = [];
        if (!empty($wantedIds)) {
            $templateNames = DB::table('certificate_templates')
                ->whereIn('id', array_keys($wantedIds))
                ->pluck('name', 'id');
        }
        $wantedCardIds = [];
        foreach ($rows as $row) {
            $cardKey = $row->card_template_key ?? null;
            if ($cardKey !== null && ctype_digit((string) $cardKey)) {
                $wantedCardIds[(int) $cardKey] = true;
            }
        }
        $cardTemplateNames = [];
        if (!empty($wantedCardIds)) {
            $cardTemplateNames = DB::table('event_card_templates')
                ->whereIn('id', array_keys($wantedCardIds))
                ->pluck('name', 'id');
        }
        foreach ($rows as $row) {
            $key = $row->certificate_template_key ?? null;
            $numericId = ($key !== null && ctype_digit((string) $key)) ? (int) $key : null;
            $row->certificate_template_id = $numericId;
            $row->certificate_template_name = ($numericId !== null && isset($templateNames[$numericId]))
                ? $templateNames[$numericId]
                : null;

            $cardKey = $row->card_template_key ?? null;
            $cardNumericId = ($cardKey !== null && ctype_digit((string) $cardKey)) ? (int) $cardKey : null;
            $row->card_template_id = $cardNumericId;
            $row->card_template_name = ($cardNumericId !== null && isset($cardTemplateNames[$cardNumericId]))
                ? $cardTemplateNames[$cardNumericId]
                : null;
        }

        $response = [
            'status' => 'success',
            'data' => $rows
        ];

        if ($includeMeta) {
            $response['pagination'] = [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ];
        }

        return response()->json($response);
    }

    public function storeTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'eventId' => 'required|integer|min:1',
            'name' => 'required|string|min:2',
            'templateType' => 'nullable|in:image,pdf',
            'templateUrl' => 'required|string|min:2',
            'fieldPositions' => 'nullable|array',
            'isDefault' => 'nullable|boolean',
            'isActive' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $data = $validator->validated();
        $eventId = (int) $data['eventId'];
        $user = $request->user();

        if (!$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $templateType = $data['templateType'] ?? 'image';
        $fieldPositions = $data['fieldPositions'] ?? [];
        $isDefault = $data['isDefault'] ?? false;
        $isActive = $data['isActive'] ?? true;

        $insertId = null;

        DB::transaction(function () use (&$insertId, $eventId, $data, $templateType, $fieldPositions, $isDefault, $isActive) {
            if ($isDefault) {
                DB::table('certificate_templates')
                    ->where('event_id', $eventId)
                    ->update(['is_default' => 0]);
            }

            $insertId = DB::table('certificate_templates')->insertGetId([
                'event_id' => $eventId,
                'name' => $data['name'],
                'template_type' => $templateType,
                'template_url' => $data['templateUrl'],
                'field_positions_json' => json_encode($fieldPositions),
                'is_default' => $isDefault ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Certificate template saved',
            'data' => array_merge(['id' => $insertId], $data)
        ]);
    }

    public function updateTemplateStatus(Request $request, $id)
    {
        $isActive = filter_var($request->input('isActive', false), FILTER_VALIDATE_BOOLEAN);

        $template = DB::table('certificate_templates')->where('id', $id)->first();
        if (!$template) {
            return response()->json([
                'status' => 'error',
                'message' => 'Template not found'
            ], 404);
        }

        $user = $request->user();
        if (!$this->requireEventScope($user, $template->event_id)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        DB::table('certificate_templates')->where('id', $id)->update([
            'is_active' => $isActive ? 1 : 0,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Template status updated',
            'data' => [
                'id' => (int) $id,
                'isActive' => $isActive
            ]
        ]);
    }

    public function setDefaultTemplate(Request $request, $id)
    {
        $template = DB::table('certificate_templates')->where('id', $id)->first();
        if (!$template) {
            return response()->json([
                'status' => 'error',
                'message' => 'Template not found'
            ], 404);
        }

        $user = $request->user();
        if (!$this->requireEventScope($user, $template->event_id)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        DB::transaction(function () use ($template, $id) {
            DB::table('certificate_templates')
                ->where('event_id', $template->event_id)
                ->update(['is_default' => 0]);
            DB::table('certificate_templates')
                ->where('id', $id)
                ->update(['is_default' => 1, 'is_active' => 1, 'updated_at' => now()]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Default template updated',
            'data' => ['id' => (int) $id, 'eventId' => (int) $template->event_id]
        ]);
    }

    public function destroyTemplate(Request $request, $id)
    {
        $template = DB::table('certificate_templates')->where('id', $id)->first();
        if (!$template) {
            return response()->json([
                'status' => 'error',
                'message' => 'Template not found'
            ], 404);
        }

        $user = $request->user();
        if (!$this->requireEventScope($user, $template->event_id)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // Issued certificates reference the template: refuse so previews keep working.
        $usageCount = DB::table('certificates')
            ->where('template_key', (string) $id)
            ->count();
        if ($usageCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Template is used by {$usageCount} issued certificate(s) and cannot be deleted",
                'details' => ['usageCount' => $usageCount]
            ], 409);
        }

        DB::table('certificate_templates')->where('id', $id)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Template deleted',
            'data' => ['id' => (int) $id]
        ]);
    }

    // ------------------------------------------------------------------
    // Event card designs: same model as certificate templates — multiple
    // designs per event, one default, manual per-attendee assignment.
    // ------------------------------------------------------------------

    private function resolveCardTemplate(int $eventId, $templateKeyOrId = null)
    {
        if ($templateKeyOrId !== null && ctype_digit((string) $templateKeyOrId)) {
            $explicit = DB::table('event_card_templates')
                ->where('id', (int) $templateKeyOrId)
                ->where('event_id', $eventId)
                ->first();
            if ($explicit) return $explicit;
        }
        return DB::table('event_card_templates')
            ->where('event_id', $eventId)
            ->orderBy('is_default', 'desc')
            ->orderBy('updated_at', 'desc')
            ->first();
    }

    public function getCardTemplates(Request $request)
    {
        $eventId = (int) $request->query('eventId', 0);
        $user = $request->user();

        if ($eventId !== 0 && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $query = DB::table('event_card_templates as ct')
            ->join('events as e', 'e.id', '=', 'ct.event_id')
            ->select(
                'ct.id',
                'ct.event_id',
                'ct.name',
                'ct.background_url',
                'ct.fields_json',
                'ct.is_default',
                'ct.is_active',
                'ct.created_at',
                'ct.updated_at'
            )
            ->orderBy('ct.is_default', 'desc')
            ->orderBy('ct.updated_at', 'desc');

        if ($eventId !== 0) {
            $query->where('ct.event_id', $eventId);
        }

        $this->applyEventScope($query, $user, 'ct.event_id');

        $rows = $query->get()->map(function ($row) {
            $row->is_default = (bool) $row->is_default;
            $row->is_active = (bool) $row->is_active;
            return $row;
        });

        return response()->json([
            'status' => 'success',
            'data' => $rows
        ]);
    }

    public function storeCardTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'eventId' => 'required|integer|min:1',
            'name' => 'required|string|min:2',
            'backgroundUrl' => 'nullable|string',
            'fields' => 'nullable|array',
            'isDefault' => 'nullable|boolean',
            'isActive' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $data = $validator->validated();
        $eventId = (int) $data['eventId'];
        $user = $request->user();

        if (!$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $fields = $data['fields'] ?? [];
        $isDefault = $data['isDefault'] ?? false;
        $isActive = $data['isActive'] ?? true;

        $insertId = null;

        DB::transaction(function () use (&$insertId, $eventId, $data, $fields, $isDefault, $isActive) {
            if ($isDefault) {
                DB::table('event_card_templates')
                    ->where('event_id', $eventId)
                    ->update(['is_default' => 0]);
            }

            $insertId = DB::table('event_card_templates')->insertGetId([
                'event_id' => $eventId,
                'name' => $data['name'],
                'background_url' => $data['backgroundUrl'] ?? null,
                'fields_json' => json_encode($fields),
                'is_default' => $isDefault ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Event card template saved',
            'data' => array_merge(['id' => $insertId], $data)
        ]);
    }

    public function updateCardTemplateStatus(Request $request, $id)
    {
        $isActive = filter_var($request->input('isActive', false), FILTER_VALIDATE_BOOLEAN);

        $template = DB::table('event_card_templates')->where('id', $id)->first();
        if (!$template) {
            return response()->json([
                'status' => 'error',
                'message' => 'Template not found'
            ], 404);
        }

        $user = $request->user();
        if (!$this->requireEventScope($user, $template->event_id)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        DB::table('event_card_templates')->where('id', $id)->update([
            'is_active' => $isActive ? 1 : 0,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Template status updated',
            'data' => [
                'id' => (int) $id,
                'isActive' => $isActive
            ]
        ]);
    }

    public function setDefaultCardTemplate(Request $request, $id)
    {
        $template = DB::table('event_card_templates')->where('id', $id)->first();
        if (!$template) {
            return response()->json([
                'status' => 'error',
                'message' => 'Template not found'
            ], 404);
        }

        $user = $request->user();
        if (!$this->requireEventScope($user, $template->event_id)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        DB::transaction(function () use ($template, $id) {
            DB::table('event_card_templates')
                ->where('event_id', $template->event_id)
                ->update(['is_default' => 0]);
            DB::table('event_card_templates')
                ->where('id', $id)
                ->update(['is_default' => 1, 'is_active' => 1, 'updated_at' => now()]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Default card template updated',
            'data' => ['id' => (int) $id, 'eventId' => (int) $template->event_id]
        ]);
    }

    public function destroyCardTemplate(Request $request, $id)
    {
        $template = DB::table('event_card_templates')->where('id', $id)->first();
        if (!$template) {
            return response()->json([
                'status' => 'error',
                'message' => 'Template not found'
            ], 404);
        }

        $user = $request->user();
        if (!$this->requireEventScope($user, $template->event_id)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $usageCount = DB::table('event_cards')
            ->where('template_key', (string) $id)
            ->count();
        if ($usageCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Template is used by {$usageCount} issued event card(s) and cannot be deleted",
                'details' => ['usageCount' => $usageCount]
            ], 409);
        }

        DB::table('event_card_templates')->where('id', $id)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Template deleted',
            'data' => ['id' => (int) $id]
        ]);
    }

    public function issue(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'attendeeId' => 'required|integer|min:1',
            'templateKey' => 'nullable|string|min:1',
            'fileUrl' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $data = $validator->validated();
        $attendeeId = (int) $data['attendeeId'];
        $templateKey = $data['templateKey'] ?? 'default';
        $fileUrl = $data['fileUrl'] ?? null;

        $attendee = DB::table('attendees as a')
            ->leftJoin('events as e', 'e.id', '=', 'a.event_id')
            ->select('a.id', 'a.event_id', 'a.full_name', 'a.checked_in_at', 'e.title_en as event_title_en')
            ->where('a.id', $attendeeId)
            ->first();

        if (!$attendee) {
            return response()->json([
                'status' => 'error',
                'message' => 'Attendee not found'
            ], 404);
        }

        // Legacy "default" callers (e.g. attendee detail page) must not wipe an
        // explicitly assigned template on re-issue: keep the stored choice.
        if ($templateKey === 'default') {
            $existingKey = DB::table('certificates')->where('attendee_id', $attendee->id)->value('template_key');
            if ($existingKey !== null && ctype_digit((string) $existingKey)) {
                $stillValid = DB::table('certificate_templates')
                    ->where('id', (int) $existingKey)
                    ->where('event_id', $attendee->event_id)
                    ->exists();
                if ($stillValid) $templateKey = (string) $existingKey;
            }
        }

        // A numeric templateKey is an explicit template choice: it must exist
        // and belong to the attendee's event (multi-template support).
        if (ctype_digit((string) $templateKey)) {
            $chosen = DB::table('certificate_templates')
                ->where('id', (int) $templateKey)
                ->where('event_id', $attendee->event_id)
                ->first();
            if (!$chosen) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Certificate template not found for this event'
                ], 422);
            }
        }

        $user = $request->user();
        if (!$this->requireEventScope($user, $attendee->event_id)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        if (!$attendee->checked_in_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Certificate can be issued after check-in'
            ], 422);
        }

        // Full-attendance rule: for events with known dates, the certificate
        // requires one daily check-in for every event day (counted only inside
        // the event window, so early/late scans can't substitute a missed day).
        // Events without dates keep the legacy first-check-in requirement above.
        $event = DB::table('events')->where('id', $attendee->event_id)->first(['starts_at', 'ends_at']);
        if ($event && $event->starts_at && $event->ends_at) {
            $windowStart = Carbon::parse($event->starts_at)->toDateString();
            $windowEnd = Carbon::parse($event->ends_at)->toDateString();
            $requiredDays = Carbon::parse($windowStart)->diffInDays(Carbon::parse($windowEnd)) + 1;
            $daysAttended = DB::table('attendee_daily_checkins')
                ->where('attendee_id', $attendee->id)
                ->where('event_id', $attendee->event_id)
                ->where('checkin_date', '>=', $windowStart)
                ->where('checkin_date', '<=', $windowEnd)
                ->count();
            if ($daysAttended < $requiredDays) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Certificate requires attendance on all event days ({$daysAttended}/{$requiredDays})",
                    'details' => ['daysAttended' => $daysAttended, 'requiredDays' => $requiredDays]
                ], 422);
            }
        }

        $issued = null;

        DB::transaction(function () use (&$issued, $attendee, $templateKey, $fileUrl) {
            $existing = DB::table('certificates')->where('attendee_id', $attendee->id)->first();

            if ($existing) {
                DB::table('certificates')->where('id', $existing->id)->update([
                    'status' => 'issued',
                    'event_id' => $attendee->event_id,
                    'template_key' => $templateKey,
                    'file_url' => $fileUrl,
                    'issued_at' => now(),
                ]);

                $issued = [
                    'id' => $existing->id,
                    'certificateNumber' => $existing->certificate_number,
                    'templateKey' => $templateKey,
                ];
            } else {
                $nextCertificateNumber = $this->generateCertificateNumber();
                $insertId = DB::table('certificates')->insertGetId([
                    'attendee_id' => $attendee->id,
                    'event_id' => $attendee->event_id,
                    'certificate_number' => $nextCertificateNumber,
                    'template_key' => $templateKey,
                    'file_url' => $fileUrl,
                    'status' => 'issued',
                    'issued_at' => now(),
                    'created_at' => now(),
                ]);

                $issued = [
                    'id' => $insertId,
                    'certificateNumber' => $nextCertificateNumber,
                    'templateKey' => $templateKey,
                ];
            }
            DB::table('attendees')->where('id', $attendee->id)->update([
                'certificate_issued_at' => now(),
            ]);
        });

        $registrationId = DB::table('generated_tickets as gt')
            ->join('registrations as r', 'r.id', '=', 'gt.registration_id')
            ->where('gt.attendee_id', $attendee->id)
            ->value('r.id');
        if ($registrationId) {
            app(UserNotificationService::class)->notifyRegistrationUser(
                (int) $registrationId,
                'certificate_available',
                'Certificate Available',
                'Your certificate is ready.',
                [
                    'title_ar' => 'الشهادة جاهزة',
                    'message_ar' => 'شهادتك جاهزة.',
                    'entity_type' => 'certificate',
                    'entity_id' => $issued['id'] ?? null,
                    'action_url' => '/dashboard/certificates',
                    'dedupe_key' => 'certificate_available:certificate:' . ($issued['id'] ?? $attendee->id),
                ]
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Certificate issued',
            'data' => $issued
        ]);
    }

    public function eventCard(Request $request)
    {
        $attendeeId = (int) $request->input('attendeeId', 0);
        $templateKey = $request->input('templateKey', 'default');
        $fileUrl = $request->input('fileUrl', null);

        if (!$attendeeId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Attendee is required'
            ], 400);
        }

        $attendee = DB::table('attendees')->select('id', 'event_id')->where('id', $attendeeId)->first();
        if (!$attendee) {
            return response()->json([
                'status' => 'error',
                'message' => 'Attendee not found'
            ], 404);
        }

        $user = $request->user();
        if (!$this->requireEventScope($user, $attendee->event_id)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // Legacy "default" callers must not wipe an explicitly assigned card
        // design on re-generate: keep the stored choice.
        if ($templateKey === 'default') {
            $existingKey = DB::table('event_cards')->where('attendee_id', $attendee->id)->value('template_key');
            if ($existingKey !== null && ctype_digit((string) $existingKey)) {
                $stillValid = DB::table('event_card_templates')
                    ->where('id', (int) $existingKey)
                    ->where('event_id', $attendee->event_id)
                    ->exists();
                if ($stillValid) $templateKey = (string) $existingKey;
            }
        }

        // A numeric templateKey is an explicit card design choice: it must
        // exist and belong to the attendee's event.
        if (ctype_digit((string) $templateKey)) {
            $chosen = DB::table('event_card_templates')
                ->where('id', (int) $templateKey)
                ->where('event_id', $attendee->event_id)
                ->first();
            if (!$chosen) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Event card template not found for this event'
                ], 422);
            }
        }

        $created = null;

        DB::transaction(function () use (&$created, $attendee, $templateKey, $fileUrl) {
            $existing = DB::table('event_cards')->where('attendee_id', $attendee->id)->first();

            if ($existing) {
                DB::table('event_cards')->where('id', $existing->id)->update([
                    'event_id' => $attendee->event_id,
                    'template_key' => $templateKey,
                    'file_url' => $fileUrl,
                ]);

                $created = [
                    'id' => $existing->id,
                    'cardNumber' => $existing->card_number,
                    'templateKey' => $templateKey,
                ];
            } else {
                $nextCardNumber = $this->generateCardNumber();
                $insertId = DB::table('event_cards')->insertGetId([
                    'attendee_id' => $attendee->id,
                    'event_id' => $attendee->event_id,
                    'card_number' => $nextCardNumber,
                    'template_key' => $templateKey,
                    'file_url' => $fileUrl,
                    'created_at' => now(),
                ]);

                $created = [
                    'id' => $insertId,
                    'cardNumber' => $nextCardNumber,
                    'templateKey' => $templateKey,
                ];
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Event card generated',
            'data' => $created
        ]);
    }

    public function bulkEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'certificateIds' => 'required|array|min:1|max:500',
            'certificateIds.*' => 'integer|min:1',
            'eventId' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 400);
        }

        $data = $validator->validated();
        $certificateIds = array_values(array_unique(array_map('intval', $data['certificateIds'])));
        $eventId = isset($data['eventId']) ? (int) $data['eventId'] : 0;
        $user = $request->user();

        if ($eventId !== 0 && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $rows = DB::table('certificates as c')
            ->join('attendees as a', 'a.id', '=', 'c.attendee_id')
            ->join('events as e', 'e.id', '=', 'a.event_id')
            ->leftJoin('ticket_types as tt', 'tt.id', '=', 'a.ticket_type_id')
            ->leftJoin('orders as o', 'o.id', '=', 'a.order_id')
            ->leftJoin('doctors as d', 'd.email', '=', 'a.email')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->whereIn('c.id', $certificateIds)
            ->select(
                'c.id as certificate_id',
                'c.certificate_number',
                'c.file_url',
                'c.status as certificate_status',
                'c.issued_at',
                'a.id as attendee_id',
                'a.attendee_number',
                'a.full_name',
                'a.email as attendee_email',
                'a.event_id',
                'e.title_en as event_title_en',
                'e.title_ar as event_title_ar',
                'tt.name_en as ticket_name_en',
                'o.order_number',
                'd.preferred_language as doctor_language',
                'u.preferred_language as user_language'
            )
            ->get()
            ->keyBy('certificate_id');

        $results = [];

        foreach (array_chunk($certificateIds, 50) as $chunk) {
            foreach ($chunk as $certificateId) {
                $row = $rows->get($certificateId);

                if (!$row) {
                    $results[] = [
                        'certificateId' => $certificateId,
                        'recipient' => 'Unknown',
                        'email' => '',
                        'status' => 'failed',
                        'message' => 'Certificate not found',
                    ];
                    continue;
                }

                if ($eventId !== 0 && (int) $row->event_id !== $eventId) {
                    $results[] = [
                        'certificateId' => (int) $row->certificate_id,
                        'attendeeId' => (int) $row->attendee_id,
                        'recipient' => $row->full_name,
                        'email' => $row->attendee_email ?: '',
                        'status' => 'failed',
                        'message' => 'Certificate is not in the selected event',
                    ];
                    $this->auditLog($request, 'certificate.email_failed', 'certificate', $row->certificate_id, [
                        'attendee_id' => $row->attendee_id,
                        'event_id' => $row->event_id,
                        'reason' => 'event_mismatch',
                    ]);
                    continue;
                }

                if (!$this->requireEventScope($user, $row->event_id)) {
                    $results[] = [
                        'certificateId' => (int) $row->certificate_id,
                        'attendeeId' => (int) $row->attendee_id,
                        'recipient' => $row->full_name,
                        'email' => '',
                        'status' => 'failed',
                        'message' => 'Forbidden',
                    ];
                    continue;
                }

                if ($row->certificate_status !== 'issued') {
                    $results[] = [
                        'certificateId' => (int) $row->certificate_id,
                        'attendeeId' => (int) $row->attendee_id,
                        'recipient' => $row->full_name,
                        'email' => $row->attendee_email ?: '',
                        'status' => 'failed',
                        'message' => 'Certificate is not issued',
                    ];
                    continue;
                }

                $email = trim((string) $row->attendee_email);
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $results[] = [
                        'certificateId' => (int) $row->certificate_id,
                        'attendeeId' => (int) $row->attendee_id,
                        'recipient' => $row->full_name,
                        'email' => $email,
                        'status' => 'missing_email',
                        'message' => $email === '' ? 'Missing email' : 'Invalid email',
                    ];
                    $this->auditLog($request, 'certificate.email_failed', 'certificate', $row->certificate_id, [
                        'attendee_id' => $row->attendee_id,
                        'event_id' => $row->event_id,
                        'reason' => $email === '' ? 'missing_email' : 'invalid_email',
                    ]);
                    continue;
                }

                $locale = in_array($row->user_language, ['ar', 'en'], true)
                    ? $row->user_language
                    : (in_array($row->doctor_language, ['ar', 'en'], true) ? $row->doctor_language : 'en');

                $payload = [
                    'recipientName' => $row->full_name,
                    'email' => $email,
                    'eventName' => $locale === 'ar'
                        ? ($row->event_title_ar ?: $row->event_title_en)
                        : ($row->event_title_en ?: $row->event_title_ar),
                    'certificateNumber' => $row->certificate_number,
                    'attendeeNumber' => $row->attendee_number,
                    'orderNumber' => $row->order_number,
                    'certificateUrl' => $this->frontendUrl('/dashboard/certificates'),
                    'locale' => $locale,
                    'brandName' => config('mail.from.name') ?: config('app.name', 'Stylish Holidays'),
                ];

                try {
                    PlatformMailer::send($email, new CertificateDeliveryMail($payload));

                    $results[] = [
                        'certificateId' => (int) $row->certificate_id,
                        'attendeeId' => (int) $row->attendee_id,
                        'recipient' => $row->full_name,
                        'email' => $email,
                        'status' => 'sent',
                        'message' => 'Sent',
                    ];
                    $this->auditLog($request, 'certificate.email_sent', 'certificate', $row->certificate_id, [
                        'attendee_id' => $row->attendee_id,
                        'event_id' => $row->event_id,
                        'email_domain' => substr(strrchr($email, '@') ?: '', 1),
                    ]);
                } catch (\Throwable $error) {
                    report($error);
                    $results[] = [
                        'certificateId' => (int) $row->certificate_id,
                        'attendeeId' => (int) $row->attendee_id,
                        'recipient' => $row->full_name,
                        'email' => $email,
                        'status' => 'failed',
                        'message' => 'Email delivery failed',
                    ];
                    $this->auditLog($request, 'certificate.email_failed', 'certificate', $row->certificate_id, [
                        'attendee_id' => $row->attendee_id,
                        'event_id' => $row->event_id,
                        'reason' => 'mail_transport_failed',
                    ]);
                }
            }
        }

        $summary = [
            'selected' => count($certificateIds),
            'sent' => count(array_filter($results, fn ($item) => $item['status'] === 'sent')),
            'failed' => count(array_filter($results, fn ($item) => $item['status'] === 'failed')),
            'missingEmail' => count(array_filter($results, fn ($item) => $item['status'] === 'missing_email')),
        ];

        $this->auditLog($request, 'certificate.bulk_email', 'certificate', null, $summary);

        return response()->json([
            'status' => 'success',
            'message' => 'Certificate email batch processed',
            'data' => [
                'summary' => $summary,
                'results' => $results,
            ],
        ]);
    }
}
