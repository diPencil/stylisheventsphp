<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class TicketPricePeriodController extends Controller
{
    public function index(Request $request, $ticketTypeId)
    {
        $ticketType = DB::selectOne("
            SELECT tt.id, tt.event_id, e.status AS event_status
            FROM ticket_types tt
            JOIN events e ON e.id = tt.event_id
            WHERE tt.id = ?
            LIMIT 1
        ", [$ticketTypeId]);

        if (!$ticketType) {
            return response()->json(['success' => false, 'message' => 'Ticket type not found'], 404);
        }

        if ($ticketType->event_status !== 'published') {
            $user = auth('api')->user();
            if (!$user || !$user->hasPermission('pricing.manage')) {
                return response()->json(['success' => false, 'message' => 'Ticket type not found'], 404);
            }

            $roles = [$user->role->code ?? ''];
            if (!in_array('admin', $roles)) {
                $isOrganizer = DB::selectOne('SELECT id FROM events WHERE id = ? AND organizer_id = ?', [$ticketType->event_id, $user->id]);
                if (!$isOrganizer) {
                    return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
                }
            }
        }

        $rows = DB::select("
            SELECT
              id,
              ticket_type_id,
              label_en,
              label_ar,
              price,
              price_egp,
              price_usd,
              starts_at,
              ends_at,
              is_active
            FROM ticket_price_periods
            WHERE ticket_type_id = ?
            ORDER BY starts_at ASC
        ", [$ticketTypeId]);

        $formatDate = function ($date) {
            return $date ? Carbon::parse($date, 'Africa/Cairo')->setTimezone('UTC')->format('Y-m-d\TH:i:s.000\Z') : null;
        };

        $mapped = array_map(function($r) use ($formatDate) {
            return [
                'id' => (int)$r->id,
                'ticket_type_id' => (int)$r->ticket_type_id,
                'label_en' => $r->label_en,
                'label_ar' => $r->label_ar,
                'price' => $r->price !== null ? number_format((float)$r->price, 2, '.', '') : null,
                'price_egp' => $r->price_egp !== null ? number_format((float)$r->price_egp, 2, '.', '') : null,
                'price_usd' => $r->price_usd !== null ? number_format((float)$r->price_usd, 2, '.', '') : null,
                'starts_at' => $formatDate($r->starts_at),
                'ends_at' => $formatDate($r->ends_at),
                'is_active' => (int)$r->is_active,
            ];
        }, $rows);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $mapped
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'ticketTypeId' => 'required|integer|min:1',
            'labelEn' => 'required|string|min:2',
            'labelAr' => 'required|string|min:2',
            'price' => 'nullable|numeric|min:0',
            'priceEgp' => 'nullable|numeric|min:0',
            'priceUsd' => 'nullable|numeric|min:0',
            'startsAt' => 'required|string|min:1',
            'endsAt' => 'required|string|min:1',
            'isActive' => 'nullable|boolean'
        ]);

        $ticketType = DB::selectOne("
            SELECT tt.id, tt.event_id
            FROM ticket_types tt
            WHERE tt.id = ?
            LIMIT 1
        ", [$validated['ticketTypeId']]);

        if (!$ticketType) {
            return response()->json(['success' => false, 'message' => 'Ticket type not found'], 404);
        }

        $user = auth('api')->user();
        $roles = [$user->role->code ?? ''];
        if (!in_array('admin', $roles)) {
            $isOrganizer = DB::selectOne('SELECT id FROM events WHERE id = ? AND organizer_id = ?', [$ticketType->event_id, $user->id]);
            if (!$isOrganizer) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        $basePrice = $validated['price'] ?? $validated['priceEgp'] ?? $validated['priceUsd'] ?? 0;

        $period = [
            'ticket_type_id' => $validated['ticketTypeId'],
            'label_en' => $validated['labelEn'],
            'label_ar' => $validated['labelAr'],
            'price' => $basePrice,
            'starts_at' => $validated['startsAt'],
            'ends_at' => $validated['endsAt'],
            'is_active' => $validated['isActive'] ?? true,
        ];

        // Only include currency-specific columns when a non-null value was explicitly provided
        if (array_key_exists('priceEgp', $validated) && $validated['priceEgp'] !== null && $validated['priceEgp'] !== '') {
            $period['price_egp'] = $validated['priceEgp'];
        }
        if (array_key_exists('priceUsd', $validated) && $validated['priceUsd'] !== null && $validated['priceUsd'] !== '') {
            $period['price_usd'] = $validated['priceUsd'];
        }

        $id = DB::table('ticket_price_periods')->insertGetId($period);

        return response()->json([
            'success' => true,
            'message' => 'Price period created',
            'data' => array_merge(['id' => $id], $validated)
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'ticketTypeId' => 'required|integer|min:1',
            'labelEn' => 'required|string|min:2',
            'labelAr' => 'required|string|min:2',
            'price' => 'nullable|numeric|min:0',
            'priceEgp' => 'nullable|numeric|min:0',
            'priceUsd' => 'nullable|numeric|min:0',
            'startsAt' => 'required|string|min:1',
            'endsAt' => 'required|string|min:1',
            'isActive' => 'nullable|boolean'
        ]);

        $id = (int)$id;
        $existing = DB::table('ticket_price_periods')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Price period not found'], 404);

        $ticketType = DB::selectOne("
            SELECT tt.id, tt.event_id
            FROM ticket_types tt
            WHERE tt.id = ?
            LIMIT 1
        ", [$existing->ticket_type_id]);

        if (!$ticketType) return response()->json(['success' => false, 'message' => 'Ticket type not found'], 404);

        $user = auth('api')->user();
        $roles = [$user->role->code ?? ''];
        if (!in_array('admin', $roles)) {
            $isOrganizer = DB::selectOne('SELECT id FROM events WHERE id = ? AND organizer_id = ?', [$ticketType->event_id, $user->id]);
            if (!$isOrganizer) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        $basePrice = $validated['price'] ?? $validated['priceEgp'] ?? $validated['priceUsd'] ?? 0;

        $period = [
            'ticket_type_id' => $validated['ticketTypeId'],
            'label_en' => $validated['labelEn'],
            'label_ar' => $validated['labelAr'],
            'price' => $basePrice,
            'starts_at' => $validated['startsAt'],
            'ends_at' => $validated['endsAt'],
            'is_active' => $validated['isActive'] ?? true,
        ];

        // Only include currency-specific columns when a non-null value was explicitly provided
        if (array_key_exists('priceEgp', $validated) && $validated['priceEgp'] !== null && $validated['priceEgp'] !== '') {
            $period['price_egp'] = $validated['priceEgp'];
        }
        if (array_key_exists('priceUsd', $validated) && $validated['priceUsd'] !== null && $validated['priceUsd'] !== '') {
            $period['price_usd'] = $validated['priceUsd'];
        }

        DB::table('ticket_price_periods')->where('id', $id)->update($period);

        return response()->json([
            'success' => true,
            'message' => 'Price period updated',
            'data' => array_merge(['id' => $id], $validated)
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'isActive' => 'required|boolean'
        ]);

        $id = (int)$id;
        $existing = DB::table('ticket_price_periods')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Price period not found'], 404);

        $ticketType = DB::selectOne("
            SELECT tt.id, tt.event_id
            FROM ticket_types tt
            WHERE tt.id = ?
            LIMIT 1
        ", [$existing->ticket_type_id]);

        if (!$ticketType) return response()->json(['success' => false, 'message' => 'Ticket type not found'], 404);

        $user = auth('api')->user();
        $roles = [$user->role->code ?? ''];
        if (!in_array('admin', $roles)) {
            $isOrganizer = DB::selectOne('SELECT id FROM events WHERE id = ? AND organizer_id = ?', [$ticketType->event_id, $user->id]);
            if (!$isOrganizer) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        DB::table('ticket_price_periods')->where('id', $id)->update(['is_active' => $validated['isActive']]);

        return response()->json([
            'success' => true,
            'message' => 'Price period status updated',
            'data' => ['id' => $id, 'isActive' => $validated['isActive']]
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $id = (int)$id;
        $existing = DB::table('ticket_price_periods')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Price period not found'], 404);

        $ticketType = DB::selectOne("
            SELECT tt.id, tt.event_id
            FROM ticket_types tt
            WHERE tt.id = ?
            LIMIT 1
        ", [$existing->ticket_type_id]);

        if (!$ticketType) return response()->json(['success' => false, 'message' => 'Ticket type not found'], 404);

        $user = auth('api')->user();
        $roles = [$user->role->code ?? ''];
        if (!in_array('admin', $roles)) {
            $isOrganizer = DB::selectOne('SELECT id FROM events WHERE id = ? AND organizer_id = ?', [$ticketType->event_id, $user->id]);
            if (!$isOrganizer) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        DB::table('ticket_price_periods')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Price period deleted',
            'data' => ['id' => $id]
        ]);
    }
}
