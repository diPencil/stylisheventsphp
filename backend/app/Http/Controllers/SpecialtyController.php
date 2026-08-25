<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SpecialtyController extends Controller
{
    private function map($row): array
    {
        return [
            'id' => (int) $row->id,
            'nameEn' => $row->name_en,
            'nameAr' => $row->name_ar,
            'isActive' => (bool) $row->is_active,
            'createdAt' => $row->created_at ?? null,
            'updatedAt' => $row->updated_at ?? null,
        ];
    }

    public function index(Request $request)
    {
        $query = DB::table('specialties')->orderBy('name_en');
        if (filter_var($request->query('activeOnly', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('is_active', 1);
        }
        return ApiResponse::ok($query->get()->map(fn ($row) => $this->map($row))->values());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nameEn' => 'required|string|min:2|max:180|unique:specialties,name_en',
            'nameAr' => 'required|string|min:2|max:180',
            'isActive' => 'nullable|boolean',
        ]);

        $id = DB::table('specialties')->insertGetId([
            'name_en' => trim($validated['nameEn']),
            'name_ar' => trim($validated['nameAr']),
            'is_active' => $validated['isActive'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ApiResponse::ok($this->map(DB::table('specialties')->find($id)), 'Specialty created');
    }

    public function update(Request $request, $id)
    {
        $id = (int) $id;
        if (!DB::table('specialties')->where('id', $id)->exists()) {
            return ApiResponse::fail('Specialty not found', 404);
        }

        $validated = $request->validate([
            'nameEn' => ['required', 'string', 'min:2', 'max:180', Rule::unique('specialties', 'name_en')->ignore($id)],
            'nameAr' => 'required|string|min:2|max:180',
            'isActive' => 'nullable|boolean',
        ]);

        DB::table('specialties')->where('id', $id)->update([
            'name_en' => trim($validated['nameEn']),
            'name_ar' => trim($validated['nameAr']),
            'is_active' => $validated['isActive'] ?? true,
            'updated_at' => now(),
        ]);

        return ApiResponse::ok($this->map(DB::table('specialties')->find($id)), 'Specialty updated');
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate(['isActive' => 'required|boolean']);
        $id = (int) $id;
        DB::table('specialties')->where('id', $id)->update([
            'is_active' => $validated['isActive'] ? 1 : 0,
            'updated_at' => now(),
        ]);
        return ApiResponse::ok($this->map(DB::table('specialties')->find($id)), 'Specialty status updated');
    }

    public function destroy($id)
    {
        $id = (int) $id;
        $referenced = DB::table('doctors')->where('specialty_id', $id)->exists()
            || DB::table('event_specialty')->where('specialty_id', $id)->exists();

        if ($referenced) {
            DB::table('specialties')->where('id', $id)->update(['is_active' => 0, 'updated_at' => now()]);
            return ApiResponse::ok($this->map(DB::table('specialties')->find($id)), 'Specialty is in use and was deactivated');
        }

        DB::table('specialties')->where('id', $id)->delete();
        return ApiResponse::ok(['id' => $id], 'Specialty deleted');
    }
}
