<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PlatformMailer
{
    public static function envDefaults(): array
    {
        $scheme = strtolower(trim((string) config('mail.mailers.smtp.encryption', '')));
        $encryption = $scheme === 'ssl' ? 'SSL' : ($scheme === 'tls' ? 'TLS' : ($scheme !== '' ? strtoupper($scheme) : 'SSL'));
        return [
            'sender' => [
                'fromName' => (string) config('mail.from.name', ''),
                'fromEmail' => (string) config('mail.from.address', ''),
            ],
            'smtp' => [
                'host' => (string) config('mail.mailers.smtp.host', ''),
                'port' => (int) config('mail.mailers.smtp.port', 465),
                'encryption' => $encryption,
                'username' => (string) config('mail.mailers.smtp.username', ''),
                'password' => (string) config('mail.mailers.smtp.password', ''),
                'timeout' => (int) config('mail.mailers.smtp.timeout', 30),
                'auth' => true,
            ],
        ];
    }

    public static function settings(): array
    {
        $defaults = [
            'sender' => ['fromName' => '', 'fromEmail' => ''],
            'smtp' => [
                'host' => '', 'port' => 465, 'encryption' => 'SSL',
                'username' => '', 'password' => '', 'timeout' => 30, 'auth' => true,
            ],
            'incoming' => [
                'protocol' => 'IMAP', 'host' => '', 'port' => 993, 'encryption' => 'SSL',
                'username' => '', 'password' => '', 'folder' => 'INBOX',
            ],
        ];

        // .env mail config acts as the base layer; saved DB settings override it.
        $defaults = array_replace_recursive($defaults, self::envDefaults());

        $stored = Cache::remember('project_settings:email_settings', now()->addMinutes(5), function () {
            $row = DB::table('project_settings')->where('setting_key', 'email_settings')->first();
            if (!$row || empty($row->setting_value)) return [];
            $decoded = json_decode((string) $row->setting_value, true);
            return is_array($decoded) ? $decoded : [];
        });

        return array_replace_recursive($defaults, is_array($stored) ? $stored : []);
    }

    public static function masked(): array
    {
        $settings = self::settings();
        // Flags reflect the effective value (DB or .env fallback); secrets are never exposed.
        $settings['smtp']['passwordConfigured'] = trim((string) ($settings['smtp']['password'] ?? '')) !== '';
        $settings['smtp']['password'] = '';
        $settings['incoming']['passwordConfigured'] = trim((string) ($settings['incoming']['password'] ?? '')) !== '';
        $settings['incoming']['password'] = '';
        return $settings;
    }

    public static function isSmtpConfigured(?array $settings = null): bool
    {
        $smtp = ($settings ?? self::settings())['smtp'] ?? [];
        return trim((string) ($smtp['host'] ?? '')) !== '';
    }

    public static function mailerConfig(array $smtp): array
    {
        $encryption = strtoupper(trim((string) ($smtp['encryption'] ?? '')));
        $scheme = $encryption === 'SSL' ? 'ssl' : ($encryption === 'TLS' || $encryption === 'STARTTLS' ? 'tls' : null);
        $auth = array_key_exists('auth', $smtp) ? (bool) $smtp['auth'] : true;

        return [
            'transport' => 'smtp',
            'host' => trim((string) ($smtp['host'] ?? '')),
            'port' => (int) ($smtp['port'] ?? ($scheme === 'ssl' ? 465 : 587)),
            'encryption' => $scheme,
            'username' => $auth ? (trim((string) ($smtp['username'] ?? '')) ?: null) : null,
            'password' => $auth ? ((string) ($smtp['password'] ?? '') !== '' ? (string) $smtp['password'] : null) : null,
            'timeout' => max(5, min(120, (int) ($smtp['timeout'] ?? 30))),
        ];
    }

    public static function apply(array $settings): string
    {
        $sender = $settings['sender'] ?? [];
        $fromEmail = trim((string) ($sender['fromEmail'] ?? ''));
        $fromName = trim((string) ($sender['fromName'] ?? '')) ?: config('mail.from.name', 'Stylish Holidays');

        if ($fromEmail !== '') {
            config(['mail.from.address' => $fromEmail, 'mail.from.name' => $fromName]);
        }

        if (self::isSmtpConfigured($settings)) {
            config(['mail.mailers.platform_smtp' => self::mailerConfig($settings['smtp'] ?? [])]);
            return 'platform_smtp';
        }

        return config('mail.default', 'smtp');
    }

    public static function send(string $to, \Illuminate\Mail\Mailable $mailable): void
    {
        $mailer = self::apply(self::settings());
        Mail::mailer($mailer)->to($to)->send($mailable);
    }

    public static function sendTest(string $to, array $settings): void
    {
        $mailer = self::apply($settings);
        $sender = $settings['sender'] ?? [];
        $fromName = trim((string) ($sender['fromName'] ?? '')) ?: 'Stylish Holidays';
        Mail::mailer($mailer)->to($to)->send(new \App\Mail\PlatformTestMail($fromName));
    }
}
