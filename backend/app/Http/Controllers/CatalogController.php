<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->hasPermission('events.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $onlyActive = !filter_var($request->query('all', false), FILTER_VALIDATE_BOOLEAN);
        $query = DB::table('event_catalogs')->select(['id', 'name_en', 'name_ar', 'is_active', 'created_at']);
        if ($onlyActive) {
            $query->where('is_active', 1);
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $query->orderBy('name_en')->get(),
        ]);
    }

    public function store(Request $request)
    {
        if (!$request->user()->hasPermission('events.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'nameEn' => 'required|string|min:2|max:120|unique:event_catalogs,name_en',
            'nameAr' => 'nullable|string|min:2|max:120',
        ]);

        $id = DB::table('event_catalogs')->insertGetId([
            'name_en' => trim($validated['nameEn']),
            'name_ar' => isset($validated['nameAr']) ? trim($validated['nameAr']) : trim($validated['nameEn']),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Catalog created',
            'data' => DB::table('event_catalogs')->where('id', $id)->first(),
        ]);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->hasPermission('events.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $existing = DB::table('event_catalogs')->where('id', (int) $id)->first();
        if (!$existing) {
            return response()->json(['success' => false, 'message' => 'Catalog not found'], 404);
        }

        $validated = $request->validate([
            'nameEn' => 'sometimes|string|min:2|max:120',
            'nameAr' => 'sometimes|nullable|string|min:2|max:120',
            'isActive' => 'sometimes|boolean',
        ]);

        $updates = ['updated_at' => now()];
        if (array_key_exists('nameEn', $validated)) $updates['name_en'] = trim($validated['nameEn']);
        if (array_key_exists('nameAr', $validated)) $updates['name_ar'] = $validated['nameAr'] !== null ? trim($validated['nameAr']) : null;
        if (array_key_exists('isActive', $validated)) $updates['is_active'] = $validated['isActive'] ? 1 : 0;

        if (isset($updates['name_en'])) {
            $duplicate = DB::table('event_catalogs')->where('name_en', $updates['name_en'])->where('id', '!=', (int) $id)->exists();
            if ($duplicate) {
                return response()->json(['success' => false, 'message' => 'Catalog name already exists'], 422);
            }
        }

        DB::table('event_catalogs')->where('id', (int) $id)->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'Catalog updated',
            'data' => DB::table('event_catalogs')->where('id', (int) $id)->first(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->hasPermission('events.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $existing = DB::table('event_catalogs')->where('id', (int) $id)->first();
        if (!$existing) {
            return response()->json(['success' => false, 'message' => 'Catalog not found'], 404);
        }

        DB::table('event_catalogs')->where('id', (int) $id)->delete();

        return response()->json(['success' => true, 'message' => 'Catalog deleted']);
    }
}
