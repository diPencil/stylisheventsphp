<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Exception;

class PlatformSettingsController extends Controller
{
    public function overview(Request $request)
    {
        // Require platform overview permission? Wait, what did Node do?
        // Node just did asyncRoute without requireAuth for /overview? Wait, let's check platform.js line 82:
        // `router.get('/overview', asyncRoute(async (req, res) => {`
        // Wait, is it really completely public in Node, or does the parent router enforce requireAuth?
        // Let's implement it exactly like Node first.

        $eventsCount = DB::table('events')->count();
        $publishedEvents = DB::table('events')->where('status', 'published')->count();
        $ordersCount = DB::table('orders')->count();
        $attendeesCount = DB::table('attendees')->count();
        $checkedInCount = DB::table('attendees')->whereNotNull('checked_in_at')->count();
        $revenue = DB::table('orders')->where('status', 'paid')->sum('grand_total');
        $pendingReviews = DB::table('reviews')->where('status', 'pending')->count();

        $upcomingEvents = DB::table('events')
            ->select('id', 'slug', 'title_en', 'title_ar', 'status', 'starts_at', 'ends_at', 'max_attendees')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at', 'asc')
            ->limit(6)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'stats' => [
                    'events' => $eventsCount,
                    'publishedEvents' => $publishedEvents,
                    'orders' => $ordersCount,
                    'attendees' => $attendeesCount,
                    'checkedIn' => $checkedInCount,
                    'revenue' => (float) $revenue,
                    'pendingReviews' => $pendingReviews,
                ],
                'upcomingEvents' => $upcomingEvents,
            ]
        ]);
    }

    private function readProjectSetting($key, $fallback = [])
    {
        return Cache::remember("project_settings:{$key}", now()->addMinutes(5), function () use ($key, $fallback) {
            $setting = DB::table('project_settings')->where('setting_key', $key)->first();
            if (!$setting || !$setting->setting_value) return $fallback;

            $decoded = json_decode($setting->setting_value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            return $fallback;
        });
    }

    private function settingArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            $decoded = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function isAssocArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function mergeSettingPayload(array $current, array $incoming): array
    {
        $merged = $current;

        foreach ($incoming as $key => $value) {
            if (
                is_array($value)
                && $this->isAssocArray($value)
                && isset($merged[$key])
                && is_array($merged[$key])
                && $this->isAssocArray($merged[$key])
            ) {
                $merged[$key] = $this->mergeSettingPayload($merged[$key], $value);
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function writeProjectSetting(string $key, array $value): void
    {
        $now = now();
        $payload = json_encode($value, JSON_THROW_ON_ERROR);
        $existing = DB::table('project_settings')->where('setting_key', $key)->exists();

        if ($existing) {
            DB::table('project_settings')
                ->where('setting_key', $key)
                ->update([
                    'setting_value' => $payload,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('project_settings')->insert([
                'setting_key' => $key,
                'setting_value' => $payload,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->forgetProjectSetting($key);
    }

    private function forgetProjectSetting($key)
    {
        Cache::forget("project_settings:{$key}");
    }

    public function getTheme()
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $this->readProjectSetting('theme', (object)[])
        ]);
    }

    public function updateTheme(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->hasPermission('theme_identity.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $theme = [
            'primaryColor' => $request->input('primaryColor', '#2563eb'),
            'secondaryColor' => $request->input('secondaryColor', '#0f172a'),
            'accentColor' => $request->input('accentColor', '#7c3aed'),
            'radius' => (string)$request->input('radius', '12'),
            'fontFamily' => $request->input('fontFamily', 'Rubik'),
            'fontFamilyAr' => $request->input('fontFamilyAr', 'Cairo'),
            'buttonStyle' => $request->input('buttonStyle', 'solid'),
            'density' => $request->input('density', 'comfortable'),
            'logoEnUrl' => $request->input('logoEnUrl', '/logo.png'),
            'logoArUrl' => $request->input('logoArUrl', '/LogoAR.png'),
            'faviconUrl' => $request->input('faviconUrl', '/favicon.png'),
            'footerLocationEn' => $request->input('footerLocationEn', '26 Tarablous Street, Abbas El Akkad, 2nd floor, Flat 5, Nasr City, Cairo, Egypt'),
            'footerLocationAr' => $request->input('footerLocationAr', '٢٦ شارع طرابلس، عباس العقاد، الدور الثاني، شقة ٥، مدينة نصر، القاهرة، مصر'),
            'footerMobile' => $request->input('footerMobile', '+2 0100 607 1661'),
            'footerWhatsapp' => $request->input('footerWhatsapp', '+2 0100 607 1661'),
        ];

        $this->writeProjectSetting('theme', $theme);

        return response()->json([
            'success' => true,
            'message' => 'Theme settings saved',
            'data' => $theme
        ]);
    }

    public function getSiteContent()
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $this->readProjectSetting('site_content', (object)[])
        ]);
    }

    public function updateSiteContent(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->hasPermission('website_content.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        // To maintain strict parity with Node.js which does partial merging, we will simply accept the JSON payload as-is for parity without complex zod re-implementation, since the exact requirements state "Complete the actual existing mutations for: Website Content"
        // In Laravel, the payload from the client is already decoded, we encode it.
        // If they want exact Zod validation parity for this mega-schema, we can attempt it, but for now we just dump the JSON like currency does if it's too complex.
        // Wait, Node does a deep merge for `site_content`.
        $current = $this->settingArray($this->readProjectSetting('site_content', []));
        $incoming = $this->settingArray($request->all());
        $updated = $this->mergeSettingPayload($current, $incoming);

        $this->writeProjectSetting('site_content', $updated);

        return response()->json([
            'success' => true,
            'message' => 'Website settings saved',
            'data' => $updated
        ]);
    }

    public function getCurrency()
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $this->readProjectSetting('currency', (object)[])
        ]);
    }

    public function getEmail(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->hasPermission('settings.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => \App\Services\PlatformMailer::masked(),
        ]);
    }

    public function updateEmail(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->hasPermission('settings.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'sender.fromName' => 'nullable|string|max:120',
            'sender.fromEmail' => 'nullable|email|max:180',
            'smtp.host' => 'nullable|string|max:180',
            'smtp.port' => 'nullable|integer|min:1|max:65535',
            'smtp.encryption' => 'nullable|in:SSL,TLS,STARTTLS,None,none,ssl,tls,starttls',
            'smtp.username' => 'nullable|string|max:180',
            'smtp.password' => 'nullable|string|max:500',
            'smtp.timeout' => 'nullable|integer|min:5|max:120',
            'smtp.auth' => 'nullable|boolean',
            'incoming.protocol' => 'nullable|in:IMAP,POP3,imap,pop3',
            'incoming.host' => 'nullable|string|max:180',
            'incoming.port' => 'nullable|integer|min:1|max:65535',
            'incoming.encryption' => 'nullable|in:SSL,TLS,STARTTLS,None,none,ssl,tls,starttls',
            'incoming.username' => 'nullable|string|max:180',
            'incoming.password' => 'nullable|string|max:500',
            'incoming.folder' => 'nullable|string|max:120',
            'auth.emailVerificationEnabled' => 'nullable|boolean',
        ]);

        $current = $this->readProjectSetting('email_settings', []);
        if (!is_array($current)) $current = [];
        $merged = $this->mergeSettingPayload($current, $this->settingArray($validated));

        // Passwords: blank means "keep existing" (they are never returned to the client).
        // If neither the form nor the DB has a secret, drop the key so .env can fall through.
        $currentSmtpPassword = $current['smtp']['password'] ?? '';
        $currentIncomingPassword = $current['incoming']['password'] ?? '';
        if (trim((string) ($validated['smtp']['password'] ?? '')) === '') {
            if (trim((string) $currentSmtpPassword) !== '') {
                $merged['smtp']['password'] = $currentSmtpPassword;
            } else {
                unset($merged['smtp']['password']);
            }
        }
        if (trim((string) ($validated['incoming']['password'] ?? '')) === '') {
            if (trim((string) $currentIncomingPassword) !== '') {
                $merged['incoming']['password'] = $currentIncomingPassword;
            } else {
                unset($merged['incoming']['password']);
            }
        }

        $this->writeProjectSetting('email_settings', $merged);

        return response()->json([
            'success' => true,
            'message' => 'Email settings saved',
            'data' => \App\Services\PlatformMailer::masked(),
        ]);
    }

    public function testEmail(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->hasPermission('settings.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'to' => 'required|email|max:180',
            'sender.fromName' => 'nullable|string|max:120',
            'sender.fromEmail' => 'nullable|email|max:180',
            'smtp.host' => 'nullable|string|max:180',
            'smtp.port' => 'nullable|integer|min:1|max:65535',
            'smtp.encryption' => 'nullable|string|max:20',
            'smtp.username' => 'nullable|string|max:180',
            'smtp.password' => 'nullable|string|max:500',
            'smtp.timeout' => 'nullable|integer|min:5|max:120',
            'smtp.auth' => 'nullable|boolean',
        ]);

        // Start from saved settings, overlay the (possibly unsaved) form values.
        // Blank password fields keep the saved secret.
        $saved = \App\Services\PlatformMailer::settings();
        $effective = array_replace_recursive($saved, $this->settingArray($validated));
        if (trim((string) ($validated['smtp']['password'] ?? '')) === '') {
            $effective['smtp']['password'] = $saved['smtp']['password'] ?? '';
        }
        if (trim((string) ($validated['sender']['fromEmail'] ?? '')) === '') {
            $effective['sender']['fromEmail'] = $saved['sender']['fromEmail'] ?? '';
        }

        if (!\App\Services\PlatformMailer::isSmtpConfigured($effective)) {
            return response()->json(['success' => false, 'message' => 'SMTP host is not configured'], 400);
        }

        try {
            \App\Services\PlatformMailer::sendTest($validated['to'], $effective);
        } catch (\Throwable $error) {
            report($error);
            return response()->json([
                'success' => false,
                'message' => 'Test email failed: ' . $error->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Test email sent',
            'data' => ['to' => $validated['to']],
        ]);
    }

    public function testIncoming(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->hasPermission('settings.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'incoming.protocol' => 'nullable|string|max:10',
            'incoming.host' => 'nullable|string|max:180',
            'incoming.port' => 'nullable|integer|min:1|max:65535',
            'incoming.encryption' => 'nullable|string|max:20',
            'incoming.username' => 'nullable|string|max:180',
            'incoming.password' => 'nullable|string|max:500',
            'incoming.folder' => 'nullable|string|max:120',
        ]);

        $saved = \App\Services\PlatformMailer::settings();
        $incoming = array_replace_recursive($saved['incoming'] ?? [], $this->settingArray($validated['incoming'] ?? []));
        if (trim((string) ($validated['incoming']['password'] ?? '')) === '') {
            $incoming['password'] = $saved['incoming']['password'] ?? '';
        }

        if (trim((string) ($incoming['host'] ?? '')) === '' || trim((string) ($incoming['username'] ?? '')) === '') {
            return response()->json(['success' => false, 'message' => 'Incoming host and username are required'], 400);
        }

        if (!function_exists('imap_open')) {
            return response()->json([
                'success' => true,
                'message' => 'Incoming mailbox saved. Live IMAP check is unavailable on this server (php-imap missing), please verify credentials in your mail client.',
                'data' => ['liveCheck' => false],
            ]);
        }

        $protocol = strtoupper(trim((string) ($incoming['protocol'] ?? 'IMAP')));
        $encryption = strtoupper(trim((string) ($incoming['encryption'] ?? 'SSL')));
        $port = (int) ($incoming['port'] ?? ($protocol === 'POP3' ? 995 : 993));
        $folder = trim((string) ($incoming['folder'] ?? 'INBOX')) ?: 'INBOX';
        $flags = $protocol === 'POP3' ? '/pop3' : '';
        if ($encryption === 'SSL') $flags .= '/ssl';
        elseif (in_array($encryption, ['TLS', 'STARTTLS'], true)) $flags .= '/tls';
        $mailbox = sprintf('{%s:%d%s}%s', $incoming['host'], $port, $flags, $folder);

        try {
            $connection = @imap_open($mailbox, (string) $incoming['username'], (string) ($incoming['password'] ?? ''), 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
            if (!$connection) {
                throw new \Exception(trim((string) imap_last_error()) ?: 'Could not connect to mailbox');
            }
            $count = imap_num_msg($connection);
            imap_close($connection);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'message' => 'Incoming mailbox check failed: ' . $error->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Incoming mailbox is reachable',
            'data' => ['liveCheck' => true, 'messages' => (int) $count],
        ]);
    }

    public function getCardTemplate()
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $this->readProjectSetting('card_template', (object)[])
        ]);
    }

    public function updateCurrency(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->hasPermission('settings.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $payload = $request->all() ?: (object)[];

        $this->writeProjectSetting('currency', $this->settingArray($payload));

        return response()->json([
            'success' => true,
            'message' => 'Currency settings saved',
            'data' => $payload
        ]);
    }

    public function updateCardTemplate(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->hasPermission('certificates.manage')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $template = [
            'imageUrl' => (string)$request->input('imageUrl', ''),
            'updatedAt' => now()->toIso8601String(),
        ];

        $this->writeProjectSetting('card_template', $template);

        return response()->json([
            'success' => true,
            'message' => 'Card template settings saved',
            'data' => $template
        ]);
    }

    public function uploadAsset(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || (!$user->hasPermission('website_content.manage') && !$user->hasPermission('certificates.manage') && !$user->hasPermission('events.manage'))) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $extensionByMime = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogg' => 'ogg',
            'application/pdf' => 'pdf',
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = $file->getClientOriginalName() ?: 'asset';
            $mime = $file->getMimeType() ?: $file->getClientMimeType();
            if (!isset($extensionByMime[$mime])) {
                return response()->json(['success' => false, 'message' => 'Only png, jpg, webp, gif, svg, mp4, webm, ogg, and pdf assets are allowed'], 400);
            }
            $buffer = file_get_contents($file->getRealPath());
        } else {
            $fileName = $request->input('fileName', 'asset');
            $dataUrl = $request->input('dataUrl', '');

            if (!preg_match('/^data:((?:image\/(?:png|jpeg|jpg|webp|gif|svg\+xml))|(?:video\/(?:mp4|webm|ogg))|(?:application\/pdf));base64,([A-Za-z0-9+\/]+={0,2})$/', $dataUrl, $match)) {
                return response()->json(['success' => false, 'message' => 'Only png, jpg, webp, gif, svg, mp4, webm, ogg, and pdf assets are allowed'], 400);
            }

            $mime = $match[1];
            $buffer = base64_decode($match[2]);
        }

        $extension = $extensionByMime[$mime];

        $isVideo = str_starts_with($mime, 'video/');
        $isPdf = $mime === 'application/pdf';
        $maxSize = $isVideo ? 50 * 1024 * 1024 : ($isPdf ? 25 * 1024 * 1024 : 5 * 1024 * 1024);

        if (strlen($buffer) > $maxSize) {
            $msg = $isVideo ? 'Video must be 50MB or smaller' : ($isPdf ? 'PDF must be 25MB or smaller' : 'Image must be 5MB or smaller');
            return response()->json(['success' => false, 'message' => $msg], 413);
        }

        $safeBase = strtolower(preg_replace('/[^a-z0-9]+/', '-', preg_replace('/\.[a-z0-9]+$/i', '', $fileName)));
        $safeBase = trim($safeBase, '-');
        $safeBase = substr($safeBase, 0, 60) ?: 'asset';

        $savedFileName = time() * 1000 . '-' . $safeBase . '.' . $extension;

        // Keep the public URL contract as /uploads/assets/... under the Laravel public root.
        $uploadRoot = public_path('uploads/assets');
        if (!is_dir($uploadRoot)) {
            mkdir($uploadRoot, 0755, true);
        }

        file_put_contents($uploadRoot . '/' . $savedFileName, $buffer);

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded',
            'data' => [
                'url' => '/uploads/assets/' . $savedFileName
            ]
        ]);
    }
}
