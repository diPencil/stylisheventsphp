<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TicketTypeController extends Controller
{
    private function requireEventScope($user, $eventId)
    {
        if (!$user) return false;
        $roles = [$user->role->code ?? ''];
        if (in_array('admin', $roles)) return true;
        if (in_array('organizer', $roles)) {
            $isOrganizer = DB::selectOne('SELECT id FROM events WHERE id = ? AND organizer_id = ?', [$eventId, $user->id]);
            return $isOrganizer ? true : false;
        }
        return false;
    }

    public function index(Request $request)
    {
        $eventId = (int) $request->query('eventId', 0);

        if ($eventId) {
            $event = DB::table('events')->where('id', $eventId)->select('id', 'status')->first();
            if (!$event) return response()->json(['status' => 'error', 'message' => 'Event not found'], 404);
            if ($event->status !== 'published') {
                $user = $request->user('api'); // auth:api is not required on this route
                $permissions = $user ? explode(',', $user->role->permissions_list ?? '') : [];
                // wait, the users have permissions through roles. Actually, Laravel middleware handles it via 'permission'
                // Let's just do a basic check since it's an API.
                // It's a public API but only shows unpublished events if user has tickets.manage.
                // In my custom JwtGuard setup, $user->hasPermission('tickets.manage') might work or I can check DB.
                // The Node code does: !req.user?.permissions?.includes('tickets.manage')
                // Let's use the simplest check:
                if (!$user || !$user->hasPermission('tickets.manage')) {
                    return response()->json(['status' => 'error', 'message' => 'Event not found'], 404);
                }
                if (!$this->requireEventScope($user, $eventId)) {
                    return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
                }
            }
        }

        $query = DB::table('ticket_types as tt')
            ->leftJoin('attendees as a', 'a.ticket_type_id', '=', 'tt.id')
            ->leftJoin('ticket_price_periods as tpp', 'tpp.ticket_type_id', '=', 'tt.id')
            ->select(
                'tt.id',
                'tt.event_id',
                'tt.name_en',
                'tt.name_ar',
                'tt.quota',
                'tt.per_order_limit',
                'tt.is_active',
                DB::raw('COUNT(DISTINCT a.id) AS sold_count'),
                DB::raw('MIN(CASE WHEN tpp.is_active = 1 THEN tpp.price_egp END) AS min_price_egp'),
                DB::raw('MAX(CASE WHEN tpp.is_active = 1 THEN tpp.price_egp END) AS max_price_egp'),
                DB::raw('MIN(CASE WHEN tpp.is_active = 1 THEN tpp.price_usd END) AS min_price_usd'),
                DB::raw('MAX(CASE WHEN tpp.is_active = 1 THEN tpp.price_usd END) AS max_price_usd')
            )
            ->groupBy('tt.id')
            ->orderBy('tt.created_at', 'desc');

        if ($eventId) {
            $query->where('tt.event_id', $eventId);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'eventId' => 'required|integer|min:1',
            'nameEn' => 'required|string|min:2',
            'nameAr' => 'required|string|min:2',
            'descriptionEn' => 'nullable|string',
            'descriptionAr' => 'nullable|string',
            'quota' => 'nullable|integer|min:1',
            'perOrderLimit' => 'nullable|integer|min:1',
            'isActive' => 'nullable|boolean'
        ]);

        $user = auth('api')->user();
        if (!$this->requireEventScope($user, $validated['eventId'])) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $ticket = [
            'event_id' => $validated['eventId'],
            'name_en' => $validated['nameEn'],
            'name_ar' => $validated['nameAr'],
            'description_en' => $validated['descriptionEn'] ?? null,
            'description_ar' => $validated['descriptionAr'] ?? null,
            'quota' => $validated['quota'] ?? null,
            'per_order_limit' => $validated['perOrderLimit'] ?? 10,
            'is_active' => $validated['isActive'] ?? true,
        ];

        $id = DB::table('ticket_types')->insertGetId($ticket);

        return response()->json([
            'success' => true,
            'message' => 'Ticket type created',
            'data' => array_merge(['id' => $id], $validated)
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'eventId' => 'required|integer|min:1',
            'nameEn' => 'required|string|min:2',
            'nameAr' => 'required|string|min:2',
            'descriptionEn' => 'nullable|string',
            'descriptionAr' => 'nullable|string',
            'quota' => 'nullable|integer|min:1',
            'perOrderLimit' => 'nullable|integer|min:1',
            'isActive' => 'nullable|boolean'
        ]);

        $id = (int)$id;
        $existing = DB::table('ticket_types')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Ticket type not found'], 404);

        $user = auth('api')->user();
        if (!$this->requireEventScope($user, $existing->event_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $ticket = [
            'event_id' => $validated['eventId'],
            'name_en' => $validated['nameEn'],
            'name_ar' => $validated['nameAr'],
            'description_en' => $validated['descriptionEn'] ?? null,
            'description_ar' => $validated['descriptionAr'] ?? null,
            'quota' => $validated['quota'] ?? null,
            'per_order_limit' => $validated['perOrderLimit'] ?? 10,
            'is_active' => $validated['isActive'] ?? true,
        ];

        DB::table('ticket_types')->where('id', $id)->update($ticket);

        return response()->json([
            'success' => true,
            'message' => 'Ticket type updated',
            'data' => array_merge(['id' => $id], $validated)
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'isActive' => 'required|boolean'
        ]);

        $id = (int)$id;
        $existing = DB::table('ticket_types')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Ticket type not found'], 404);

        $user = auth('api')->user();
        if (!$this->requireEventScope($user, $existing->event_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        DB::table('ticket_types')->where('id', $id)->update(['is_active' => $validated['isActive']]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket type status updated',
            'data' => ['id' => $id, 'isActive' => $validated['isActive']]
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $id = (int)$id;
        $existing = DB::table('ticket_types')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Ticket type not found'], 404);

        $user = auth('api')->user();
        if (!$this->requireEventScope($user, $existing->event_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $count = DB::table('ticket_price_periods')->where('ticket_type_id', $id)->count();
        if ($count > 0) return response()->json(['success' => false, 'message' => 'Cannot delete ticket type with price periods'], 400);

        DB::table('ticket_types')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Ticket type deleted',
            'data' => ['id' => $id]
        ]);
    }
}
