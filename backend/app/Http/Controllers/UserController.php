<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    protected function mapUser($user, $role)
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'countryCode' => $user->country_code,
            'countryName' => $user->country_name,
            'gender' => $user->gender,
            'username' => $user->username,
            'status' => $user->status,
            'preferredLanguage' => $user->preferred_language,
            'avatarUrl' => $user->avatar_url,
            'notes' => $user->notes,
            'lastLoginAt' => $user->last_login_at,
            'createdAt' => $user->created_at,
            'role' => [
                'code' => $role->code ?? null,
                'nameEn' => $role->name_en ?? null,
                'nameAr' => $role->name_ar ?? null,
            ],
            'specialty' => isset($user->specialty_id) ? [
                'id' => $user->specialty_id ? (int) $user->specialty_id : null,
                'nameEn' => $user->specialty_name_en ?? null,
                'nameAr' => $user->specialty_name_ar ?? null,
                'isActive' => isset($user->specialty_is_active) ? (bool) $user->specialty_is_active : null,
                'legacyName' => $user->doctor_specialty ?? null,
            ] : null,
        ];
    }

    protected function auditLog(Request $request, $action, $entityType = null, $entityId = null, $metadata = [])
    {
        $user = Auth::guard('api')->user();
        DB::table('audit_logs')->insert([
            'user_id' => $user ? $user->id : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId ? (string) $entityId : null,
            'metadata_json' => json_encode($metadata),
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('user-agent'),
        ]);
    }

    public function index(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $offset = max((int) $request->query('offset', 0), 0);
        $includeMeta = filter_var($request->query('meta', 'false'), FILTER_VALIDATE_BOOLEAN);
        $query = User::with('role')
            ->leftJoin('doctors as d', 'd.user_id', '=', 'users.id')
            ->leftJoin('specialties as s', 's.id', '=', 'd.specialty_id')
            ->select('users.*', 'd.specialty as doctor_specialty', 'd.specialty_id', 's.name_en as specialty_name_en', 's.name_ar as specialty_name_ar', 's.is_active as specialty_is_active');

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function($q) use ($search) {
                $q->where('users.name', 'LIKE', $search)
                  ->orWhere('users.email', 'LIKE', $search)
                  ->orWhere('users.username', 'LIKE', $search)
                  ->orWhere('users.phone', 'LIKE', $search);
            });
        }

        if ($request->filled('role')) {
            $query->whereHas('role', function($q) use ($request) {
                $q->where('code', $request->role);
            });
        }

        if ($request->filled('status')) {
            $query->where('users.status', $request->status);
        }

        if ($request->filled('specialtyId')) {
            $query->whereHas('role', fn ($q) => $q->where('code', 'doctor'))
                ->where('d.specialty_id', (int) $request->query('specialtyId'));
        }

        $total = (clone $query)->count();

        // Keep operational roles first, then participant roles.
        $users = $query->join('roles', 'roles.id', '=', 'users.role_id')
            ->select('users.*', 'roles.code as role_code')
            ->addSelect('d.specialty as doctor_specialty', 'd.specialty_id', 's.name_en as specialty_name_en', 's.name_ar as specialty_name_ar', 's.is_active as specialty_is_active')
            ->orderByRaw("FIELD(roles.code, 'admin', 'organizer', 'back_office', 'employee', 'chairman', 'speaker', 'doctor', 'customer')")
            ->orderBy('users.created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $mapped = $users->map(function ($u) {
            return $this->mapUser($u, $u->role);
        });

        if ($includeMeta) {
            return response()->json([
                'success' => true,
                'message' => 'OK',
                'data' => $mapped,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ]);
        }

        return ApiResponse::ok($mapped);
    }

    public function show($id)
    {
        $user = User::with('role')
            ->leftJoin('doctors as d', 'd.user_id', '=', 'users.id')
            ->leftJoin('specialties as s', 's.id', '=', 'd.specialty_id')
            ->select('users.*', 'd.specialty as doctor_specialty', 'd.specialty_id', 's.name_en as specialty_name_en', 's.name_ar as specialty_name_ar', 's.is_active as specialty_is_active')
            ->where('users.id', $id)
            ->first();
        if (!$user) return ApiResponse::fail('User not found', 404);
        return ApiResponse::ok($this->mapUser($user, $user->role));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string',
            'countryCode' => 'nullable|string|size:2',
            'countryName' => 'nullable|string|max:120',
            'gender' => 'nullable|in:male,female,not_specified',
            'username' => 'nullable|string|min:3|unique:users,username',
            'password' => 'required|string|min:8',
            'roleCode' => 'required|string|min:2',
            'status' => 'nullable|in:active,inactive,blocked',
            'preferredLanguage' => 'nullable|in:ar,en',
            'avatarUrl' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
            'specialtyId' => 'nullable|integer|exists:specialties,id',
        ]);

        $role = Role::where('code', $validated['roleCode'])->first();
        if (!$role) return ApiResponse::fail('Role not found', 400);
        if ($role->code === 'doctor' && empty($validated['specialtyId'])) {
            return ApiResponse::fail('Specialty is required for Doctor users', 422);
        }
        $specialty = null;
        if ($role->code === 'doctor') {
            $specialty = DB::table('specialties')->where('id', $validated['specialtyId'])->where('is_active', 1)->first();
            if (!$specialty) return ApiResponse::fail('Active specialty is required for Doctor users', 422);
        }

        $user = new User();
        $user->role_id = $role->id;
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->phone = $validated['phone'] ?? null;
        if (!empty($validated['countryCode'])) {
            $user->country_code = strtoupper($validated['countryCode']);
        }
        $user->country_name = $validated['countryName'] ?? null;
        $user->gender = $validated['gender'] ?? 'not_specified';
        $user->username = $validated['username'] ?? null;
        $user->password_hash = Hash::make($validated['password']);
        $user->status = $validated['status'] ?? 'active';
        $user->preferred_language = $validated['preferredLanguage'] ?? 'en';
        $user->avatar_url = $validated['avatarUrl'] ?? null;
        $user->notes = $validated['notes'] ?? null;
        $user->save();

        if ($role->code === 'doctor') {
            DB::table('doctors')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'full_name' => $user->name,
                    'mobile' => $user->phone ?: 'N/A',
                    'email' => $user->email,
                    'country_code' => $user->country_code ?: 'EG',
                    'country_name' => $user->country_name ?: 'Egypt',
                    'city' => 'N/A',
                    'specialty' => $specialty->name_en,
                    'specialty_id' => $specialty->id,
                    'nationality' => $user->country_name ?: 'N/A',
                    'preferred_language' => $user->preferred_language,
                    'status' => $user->status,
                    'updated_at' => now(),
                ]
            );
        }

        $this->auditLog($request, 'users.create', 'user', $user->id);

        $user->load('role');
        return ApiResponse::ok($this->mapUser($user, $user->role), 'User created');
    }

    protected function countOtherActiveAdmins($userId)
    {
        return User::whereHas('role', function($q) {
            $q->where('code', 'admin');
        })->where('status', 'active')->where('id', '!=', $userId)->count();
    }

    public function update(Request $request, $id)
    {
        $current = User::with('role')->find($id);
        if (!$current) return ApiResponse::fail('User not found', 404);

        $validated = $request->validate([
            'name' => 'nullable|string|min:2',
            'email' => ['nullable', 'email', Rule::unique('users')->ignore($id)],
            'phone' => 'nullable|string',
            'countryCode' => 'nullable|string|size:2',
            'countryName' => 'nullable|string|max:120',
            'gender' => 'nullable|in:male,female,not_specified',
            'username' => ['nullable', 'string', 'min:3', Rule::unique('users')->ignore($id)],
            'password' => 'nullable|string|min:8',
            'roleCode' => 'nullable|string|min:2',
            'status' => 'nullable|in:active,inactive,blocked',
            'preferredLanguage' => 'nullable|in:ar,en',
            'avatarUrl' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
            'specialtyId' => 'nullable|integer|exists:specialties,id',
        ]);

        $roleCode = $validated['roleCode'] ?? $current->role->code;
        $role = Role::where('code', $roleCode)->first();
        if (!$role) return ApiResponse::fail('Role not found', 400);
        if ($role->code === 'doctor') {
            $currentDoctorSpecialty = DB::table('doctors')->where('user_id', $current->id)->value('specialty_id');
            $requestedSpecialtyId = $validated['specialtyId'] ?? $currentDoctorSpecialty;
            if (!$requestedSpecialtyId) return ApiResponse::fail('Specialty is required for Doctor users', 422);
            $specialty = DB::table('specialties')->where('id', $requestedSpecialtyId)->where('is_active', 1)->first();
            if (!$specialty) return ApiResponse::fail('Active specialty is required for Doctor users', 422);
        } else {
            $specialty = null;
        }

        $nextStatus = $validated['status'] ?? $current->status;
        $authUser = Auth::guard('api')->user();
        if ($authUser && $current->id === $authUser->id && $nextStatus !== 'active') {
            return ApiResponse::fail('You cannot deactivate or block your own account', 400);
        }

        if ($current->role->code === 'admin' && ($role->code !== 'admin' || $nextStatus !== 'active')) {
            if ($this->countOtherActiveAdmins($current->id) < 1) {
                return ApiResponse::fail('At least one active admin account is required', 400);
            }
        }

        $current->role_id = $role->id;
        if (isset($validated['name'])) $current->name = $validated['name'];
        if (isset($validated['email'])) $current->email = $validated['email'];
        if (array_key_exists('phone', $validated)) $current->phone = $validated['phone'];
        if (array_key_exists('countryCode', $validated)) $current->country_code = $validated['countryCode'] ? strtoupper($validated['countryCode']) : null;
        if (array_key_exists('countryName', $validated)) $current->country_name = $validated['countryName'];
        if (isset($validated['gender'])) $current->gender = $validated['gender'];
        if (array_key_exists('username', $validated)) $current->username = $validated['username'];
        $current->status = $nextStatus;
        if (isset($validated['preferredLanguage'])) $current->preferred_language = $validated['preferredLanguage'];
        if (array_key_exists('avatarUrl', $validated)) $current->avatar_url = $validated['avatarUrl'];
        if (array_key_exists('notes', $validated)) $current->notes = $validated['notes'];

        if (!empty($validated['password'])) {
            $current->password_hash = Hash::make($validated['password']);
        }

        $current->save();
        if ($role->code === 'doctor') {
            DB::table('doctors')->updateOrInsert(
                ['user_id' => $current->id],
                [
                    'full_name' => $current->name,
                    'mobile' => $current->phone ?: 'N/A',
                    'email' => $current->email,
                    'country_code' => $current->country_code ?: 'EG',
                    'country_name' => $current->country_name ?: 'Egypt',
                    'city' => 'N/A',
                    'specialty' => $specialty->name_en,
                    'specialty_id' => $specialty->id,
                    'nationality' => $current->country_name ?: 'N/A',
                    'preferred_language' => $current->preferred_language,
                    'status' => $current->status,
                    'updated_at' => now(),
                ]
            );
        } else {
            DB::table('doctors')->where('user_id', $current->id)->update(['specialty_id' => null, 'updated_at' => now()]);
        }
        $this->auditLog($request, 'users.update', 'user', $current->id);

        $current->load('role');
        return ApiResponse::ok($this->mapUser($current, $current->role), 'User updated');
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,blocked',
        ]);

        $current = User::with('role')->find($id);
        if (!$current) return ApiResponse::fail('User not found', 404);

        $authUser = Auth::guard('api')->user();
        if ($authUser && $current->id === $authUser->id && $validated['status'] !== 'active') {
            return ApiResponse::fail('You cannot deactivate or block your own account', 400);
        }

        if ($current->role->code === 'admin' && $validated['status'] !== 'active') {
            if ($this->countOtherActiveAdmins($current->id) < 1) {
                return ApiResponse::fail('At least one active admin account is required', 400);
            }
        }

        $current->status = $validated['status'];
        $current->save();

        $this->auditLog($request, "users.status.{$validated['status']}", 'user', $current->id);
        return ApiResponse::ok($this->mapUser($current, $current->role), 'User status updated');
    }

    public function updatePassword(Request $request, $id)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $current = User::find($id);
        if (!$current) return ApiResponse::fail('User not found', 404);

        $current->password_hash = Hash::make($validated['password']);
        $current->save();

        $this->auditLog($request, 'users.password_reset', 'user', $current->id);
        return ApiResponse::ok(['id' => $current->id], 'Password updated');
    }

    public function avatarUpload(Request $request)
    {
        $validated = $request->validate([
            'fileName' => 'required|string|max:180',
            'dataUrl' => 'required|string|min:30',
        ]);

        try {
            $app = app();
            $authController = $app->make(\App\Http\Controllers\AuthController::class);
            $url = $authController->saveAvatarUpload($validated['fileName'], $validated['dataUrl']);
            return ApiResponse::ok(['url' => $url], 'Avatar uploaded');
        } catch (\Exception $e) {
            return ApiResponse::fail($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}
