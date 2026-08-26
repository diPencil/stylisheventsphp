<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;

class AuthController extends Controller
{
    protected function formatUser(User $user)
    {
        $role = $user->role;
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'country_code' => $user->country_code,
            'country_name' => $user->country_name,
            'gender' => $user->gender,
            'username' => $user->username,
            'status' => $user->status,
            'preferred_language' => $user->preferred_language,
            'avatar_url' => $user->avatar_url,
            'role_code' => $role->code ?? null,
            'role_name_en' => $role->name_en ?? null,
            'role_name_ar' => $role->name_ar ?? null,
            'permissions' => DB::table('role_permissions')
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->where('roles.code', $role->code)
                ->where('role_permissions.allowed', 1)
                ->orderBy('role_permissions.permission_key')
                ->pluck('role_permissions.permission_key')
                ->toArray(),
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

    public function login(Request $request)
    {
        $validated = $request->validate([
            'login' => 'required|string|min:2',
            'password' => 'required|string|min:8',
        ]);

        $user = User::with('role')
            ->where('email', $validated['login'])
            ->orWhere('username', $validated['login'])
            ->first();

        if (! $user || $user->status !== 'active') {
            return ApiResponse::fail('Invalid credentials', 401);
        }

        if (! Hash::check($validated['password'], $user->password_hash)) {
            return ApiResponse::fail('Invalid credentials', 401);
        }

        $user->last_login_at = now();
        $user->save();

        $this->auditLog($request, 'auth.login', 'user', $user->id);

        $token = Auth::guard('api')->createToken($user);

        return ApiResponse::ok([
            'user' => $this->formatUser($user),
            'token' => $token,
        ], 'Logged in successfully');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2',
            'email' => 'required|email',
            'username' => 'nullable|string|min:3',
            'phone' => 'nullable|string|min:4',
            'company' => 'nullable|string|max:180',
            'countryCode' => 'required|string|size:2',
            'countryName' => 'required|string|min:2|max:120',
            'gender' => 'nullable|in:male,female,not_specified',
            'preferredLanguage' => 'nullable|in:ar,en',
            'avatarUrl' => 'nullable|string|max:500',
            'password' => 'required|string|min:8',
            'accountType' => 'nullable|in:customer,doctor',
            'roleCode' => 'nullable|string',
            'specialtyId' => 'nullable|integer|exists:specialties,id',
        ]);

        // Public signup must only accept explicit public participant roles.
        $allowedPublicRoles = ['customer', 'doctor'];

        // Reject explicit tampering: if the client provided any role-affecting fields
        // with values outside the allowed public set, reject the request.
        if ($request->has('roleCode') && $request->input('roleCode') !== null) {
            $rc = $request->input('roleCode');
            if (!in_array($rc, $allowedPublicRoles, true)) {
                return ApiResponse::fail('Invalid public role', 400);
            }
        }
        if ($request->has('accountType') && $request->input('accountType') !== null) {
            $at = $request->input('accountType');
            if (!in_array($at, $allowedPublicRoles, true)) {
                return ApiResponse::fail('Invalid public role', 400);
            }
        }
        // Disallow role_id being supplied in public signup payload to prevent privilege escalation
        if ($request->has('role_id')) {
            return ApiResponse::fail('role_id is not allowed in public signup', 400);
        }

        // Determine role code (prefer explicit allowed accountType, then roleCode, else default to customer)
        $publicRoleCode = $validated['accountType'] ?? ($validated['roleCode'] ?? 'customer');
        if (!in_array($publicRoleCode, $allowedPublicRoles, true)) {
            $publicRoleCode = 'customer';
        }

        $role = Role::where('code', $publicRoleCode)->first();
        if (! $role) {
            return ApiResponse::fail('Public role is missing', 500);
        }

        $specialty = null;
        if ($role->code === 'doctor') {
            if (empty($validated['specialtyId'])) {
                return ApiResponse::fail('Specialty is required for Doctor accounts', 422);
            }
            $specialty = DB::table('specialties')->where('id', $validated['specialtyId'])->where('is_active', 1)->first();
            if (!$specialty) {
                return ApiResponse::fail('Active specialty is required for Doctor accounts', 422);
            }
        }

        $exists = User::where('email', $validated['email'])
            ->orWhere(function ($query) use ($validated) {
                if (!empty($validated['username'])) {
                    $query->where('username', $validated['username']);
                }
            })->exists();

        if ($exists) {
            return ApiResponse::fail('Email or username already exists', 409);
        }

        $user = new User();
        $user->role_id = $role->id;
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->phone = $validated['phone'] ?? null;
        $user->country_code = strtoupper($validated['countryCode']);
        $user->country_name = $validated['countryName'];
        $user->gender = $validated['gender'] ?? 'not_specified';
        $user->username = $validated['username'] ?? null;
        $user->password_hash = Hash::make($validated['password']);
        $user->status = 'active';
        $user->preferred_language = $validated['preferredLanguage'] ?? 'en';
        $user->avatar_url = $validated['avatarUrl'] ?? null;
        $user->notes = !empty($validated['company']) ? 'Company: ' . $validated['company'] : null;
        $user->save();

        if ($role->code === 'doctor') {
            DB::table('doctors')->insert([
                'user_id' => $user->id,
                'full_name' => $user->name,
                'mobile' => $user->phone ?: 'N/A',
                'email' => $user->email,
                'address' => '',
                'country_code' => $user->country_code,
                'country_name' => $user->country_name,
                'city' => 'N/A',
                'specialty' => $specialty->name_en,
                'specialty_id' => $specialty->id,
                'nationality' => $user->country_name ?: 'N/A',
                'preferred_language' => $user->preferred_language,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Auth::guard('api')->setUser($user);
        $this->auditLog($request, 'auth.register', 'user', $user->id);

        $token = Auth::guard('api')->createToken($user);

        return ApiResponse::ok([
            'user' => $this->formatUser($user),
            'token' => $token,
        ], 'Account created successfully');
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'login' => 'required|string|min:3',
        ]);

        $user = User::where('email', $validated['login'])
            ->orWhere('username', $validated['login'])
            ->first();

        if ($user) {
            Auth::guard('api')->setUser($user);
            $this->auditLog($request, 'auth.password_reset_requested', 'user', $user->id);
        }

        return ApiResponse::ok(['requested' => true], 'If this account exists, reset instructions will be sent.');
    }

    public function me(Request $request)
    {
        $user = Auth::guard('api')->user();
        if (! $user) {
            return ApiResponse::fail('Unauthenticated', 401);
        }

        $customerProfile = DB::table('doctors')->where('user_id', $user->id)->first();

        $formatted = $this->formatUser($user);
        $formatted['customer_full_name'] = $customerProfile->full_name ?? null;
        $formatted['customer_mobile'] = $customerProfile->mobile ?? null;
        $formatted['customer_address'] = $customerProfile->address ?? null;
        $formatted['customer_city'] = $customerProfile->city ?? null;
        $formatted['customer_specialty'] = $customerProfile->specialty ?? null;
        $formatted['specialty_id'] = $customerProfile->specialty_id ?? null;
        if ($customerProfile?->specialty_id) {
            $specialty = DB::table('specialties')->where('id', $customerProfile->specialty_id)->first();
            $formatted['specialty'] = $specialty ? [
                'id' => (int) $specialty->id,
                'nameEn' => $specialty->name_en,
                'nameAr' => $specialty->name_ar,
                'isActive' => (bool) $specialty->is_active,
                'legacyName' => $customerProfile->specialty,
            ] : null;
        }
        $formatted['customer_nationality'] = $customerProfile->nationality ?? null;
        $formatted['last_login_at'] = $user->last_login_at;

        return ApiResponse::ok($formatted);
    }

    public function patchMe(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|min:2',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'username' => 'nullable|string|min:3',
            'countryCode' => 'nullable|string|size:2',
            'countryName' => 'nullable|string|max:120',
            'gender' => 'nullable|in:male,female,not_specified',
            'preferredLanguage' => 'nullable|in:ar,en',
            'avatarUrl' => 'nullable|string|max:500',
            'specialtyId' => 'nullable|integer|exists:specialties,id',
        ]);

        $current = Auth::guard('api')->user();

        DB::transaction(function () use ($validated, $current) {
            if (isset($validated['name'])) $current->name = $validated['name'];
            if (isset($validated['email'])) $current->email = $validated['email'];
            if (array_key_exists('phone', $validated)) $current->phone = $validated['phone'];
            if (array_key_exists('countryCode', $validated)) $current->country_code = $validated['countryCode'] ? strtoupper($validated['countryCode']) : null;
            if (array_key_exists('countryName', $validated)) $current->country_name = $validated['countryName'];
            if (isset($validated['gender'])) $current->gender = $validated['gender'];
            if (array_key_exists('username', $validated)) $current->username = $validated['username'];
            if (isset($validated['preferredLanguage'])) $current->preferred_language = $validated['preferredLanguage'];
            if (array_key_exists('avatarUrl', $validated)) $current->avatar_url = $validated['avatarUrl'];

            $current->save();

            if (in_array($current->role->code, ['customer', 'doctor'], true)) {
                $doctorUpdates = [];
                if (isset($validated['name'])) $doctorUpdates['full_name'] = $validated['name'];
                if (array_key_exists('phone', $validated)) $doctorUpdates['mobile'] = $validated['phone'] ?: 'N/A';
                if (array_key_exists('countryCode', $validated)) $doctorUpdates['country_code'] = $current->country_code ?: 'EG';
                if (array_key_exists('countryName', $validated)) {
                    $doctorUpdates['country_name'] = $current->country_name ?: 'Egypt';
                    $doctorUpdates['nationality'] = $current->country_name ?: 'N/A';
                }
                if (isset($validated['preferredLanguage'])) $doctorUpdates['preferred_language'] = $validated['preferredLanguage'];
                if ($current->role->code === 'doctor' && array_key_exists('specialtyId', $validated)) {
                    $specialty = DB::table('specialties')->where('id', $validated['specialtyId'])->where('is_active', 1)->first();
                    if (!$specialty) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['specialtyId' => 'Active specialty is required.']);
                    }
                    $doctorUpdates['specialty_id'] = $specialty->id;
                    $doctorUpdates['specialty'] = $specialty->name_en;
                }
                if (!empty($doctorUpdates)) {
                    $doctorUpdates['updated_at'] = now();
                    DB::table('doctors')->where('user_id', $current->id)->update($doctorUpdates);
                }
            }
        });

        $this->auditLog($request, 'auth.profile_update', 'user', $current->id);

        $formatted = $this->formatUser($current);
        if ($current->role->code === 'customer') {
            $formatted['customer_full_name'] = $current->name;
        } else {
            $formatted['customer_full_name'] = DB::table('doctors')->where('user_id', $current->id)->value('full_name');
        }
        $doctorProfile = DB::table('doctors')->where('user_id', $current->id)->first();
        $formatted['customer_specialty'] = $doctorProfile->specialty ?? null;
        $formatted['specialty_id'] = $doctorProfile->specialty_id ?? null;
        $formatted['specialty'] = $doctorProfile?->specialty_id
            ? DB::table('specialties')->where('id', $doctorProfile->specialty_id)->select('id', 'name_en as nameEn', 'name_ar as nameAr', 'is_active as isActive')->first()
            : null;

        return ApiResponse::ok($formatted, 'Profile updated');
    }

    public function patchPassword(Request $request)
    {
        $validated = $request->validate([
            'currentPassword' => 'required|string|min:8',
            'newPassword' => 'required|string|min:8',
        ]);

        $current = Auth::guard('api')->user();

        if (!Hash::check($validated['currentPassword'], $current->password_hash)) {
            return ApiResponse::fail('Current password is incorrect', 401);
        }

        $current->password_hash = Hash::make($validated['newPassword']);
        $current->save();

        $this->auditLog($request, 'auth.password_update', 'user', $current->id);

        return ApiResponse::ok(['id' => $current->id], 'Password updated');
    }

    public function saveAvatarUpload($fileName, $dataUrl)
    {
        if (!preg_match('/^data:(image\/(?:png|jpeg|jpg|webp));base64,([A-Za-z0-9+\/]+={0,2})$/', $dataUrl, $matches)) {
            throw new \Exception('Only png, jpg, and webp images are allowed', 400);
        }

        $mime = $matches[1];
        $base64Data = $matches[2];

        $extensionByMime = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/webp' => 'webp',
        ];
        $extension = $extensionByMime[$mime] ?? null;
        if (!$extension) {
            throw new \Exception('Only png, jpg, and webp images are allowed', 400);
        }

        $buffer = base64_decode($base64Data);

        if (strlen($buffer) > 2 * 1024 * 1024) {
            throw new \Exception('Avatar image must be 2MB or smaller', 413);
        }

        $uploadRoot = public_path('uploads/avatars');
        if (!File::exists($uploadRoot)) {
            File::makeDirectory($uploadRoot, 0755, true);
        }

        $safeBase = preg_replace('/[^a-z0-9]+/', '-', strtolower(preg_replace('/\.[a-z0-9]+$/i', '', $fileName)));
        $safeBase = trim($safeBase, '-') ?: 'avatar';
        $safeBase = substr($safeBase, 0, 60);

        $savedFileName = time() * 1000 . '-' . $safeBase . '.' . $extension;
        File::put($uploadRoot . '/' . $savedFileName, $buffer);

        return '/uploads/avatars/' . $savedFileName;
    }

    protected function removeLocalAvatar($url)
    {
        if (!$url || !str_starts_with($url, '/uploads/avatars/')) return;
        $fileName = basename($url);
        if (!$fileName) return;
        $path = public_path('uploads/avatars/' . $fileName);
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    public function avatarUpload(Request $request)
    {
        $validated = $request->validate([
            'fileName' => 'required|string|max:180',
            'dataUrl' => 'required|string|min:30',
        ]);

        try {
            $url = $this->saveAvatarUpload($validated['fileName'], $validated['dataUrl']);
            return ApiResponse::ok(['url' => $url], 'Avatar uploaded');
        } catch (\Exception $e) {
            return ApiResponse::fail($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    public function meAvatarUpload(Request $request)
    {
        $validated = $request->validate([
            'fileName' => 'required|string|max:180',
            'dataUrl' => 'required|string|min:30',
        ]);

        try {
            $current = Auth::guard('api')->user();
            $oldUrl = $current->avatar_url;
            $url = $this->saveAvatarUpload($validated['fileName'], $validated['dataUrl']);

            $current->avatar_url = $url;
            $current->save();

            $this->removeLocalAvatar($oldUrl);
            $this->auditLog($request, 'auth.avatar_update', 'user', $current->id);

            return ApiResponse::ok(['url' => $url, 'avatar_url' => $url], 'Profile photo uploaded');
        } catch (\Exception $e) {
            return ApiResponse::fail($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    public function deleteAvatar(Request $request)
    {
        $current = Auth::guard('api')->user();
        $oldUrl = $current->avatar_url;

        $current->avatar_url = null;
        $current->save();

        $this->removeLocalAvatar($oldUrl);
        $this->auditLog($request, 'auth.avatar_remove', 'user', $current->id);

        return ApiResponse::ok(['id' => $current->id, 'avatar_url' => null], 'Profile photo removed');
    }

    public function bootstrapAdmin(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|min:2',
            'email' => 'required|email',
            'username' => 'nullable|string|min:3',
            'phone' => 'nullable|string',
            'password' => 'required|string|min:8',
            'bootstrapKey' => 'nullable|string',
        ]);

        $requiredBootstrapKey = env('BOOTSTRAP_ADMIN_KEY', '');
        if ($requiredBootstrapKey && ($validated['bootstrapKey'] ?? '') !== $requiredBootstrapKey) {
            return ApiResponse::fail('Invalid bootstrap key', 403);
        }

        $existingAdmin = User::whereHas('role', function($q) {
            $q->where('code', 'admin');
        })->first();

        if ($existingAdmin) {
            return ApiResponse::fail('Admin user already exists', 409);
        }

        $adminRole = Role::where('code', 'admin')->first();
        if (!$adminRole) {
            return ApiResponse::fail('Admin role is missing', 500);
        }

        $user = new User();
        $user->role_id = $adminRole->id;
        $user->name = $validated['name'] ?? 'Super Admin';
        $user->email = $validated['email'];
        $user->phone = $validated['phone'] ?? null;
        $user->username = $validated['username'] ?? null;
        $user->password_hash = Hash::make($validated['password']);
        $user->status = 'active';
        $user->preferred_language = 'en';
        $user->save();

        $user->load('role');

        $this->auditLog($request, 'auth.bootstrap_admin', 'user', $user->id);

        $token = Auth::guard('api')->createToken($user);

        return ApiResponse::ok([
            'user' => $this->formatUser($user),
            'token' => $token,
        ], 'Admin user created');
    }
}
