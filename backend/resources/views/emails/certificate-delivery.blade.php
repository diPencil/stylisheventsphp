@php
    $isArabic = ($certificate['locale'] ?? 'en') === 'ar';
    $dir = $isArabic ? 'rtl' : 'ltr';
    $brand = $certificate['brandName'] ?? 'Stylish Events';
@endphp
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <title>{{ $isArabic ? 'شهادتك' : 'Your Certificate' }}</title>
</head>
<body style="margin:0;background:#f6f8fb;font-family:Arial,Tahoma,sans-serif;color:#172033;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f8fb;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e8edf5;">
                    <tr>
                        <td style="padding:24px 28px;background:#111827;color:#ffffff;">
                            <div style="font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">{{ $brand }}</div>
                            <div style="font-size:24px;font-weight:800;margin-top:8px;">{{ $isArabic ? 'شهادة المشاركة الخاصة بك' : 'Your participation certificate' }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            @if ($isArabic)
                                <p style="font-size:16px;line-height:1.8;margin:0 0 16px;">عزيزي/عزيزتي {{ $certificate['recipientName'] }},</p>
                                <p style="font-size:15px;line-height:1.8;margin:0 0 16px;">شكرا لمشاركتك في {{ $certificate['eventName'] }}.</p>
                                <p style="font-size:15px;line-height:1.8;margin:0 0 22px;">شهادتك جاهزة ويمكنك عرضها من خلال الرابط الآمن أدناه.</p>
                            @else
                                <p style="font-size:16px;line-height:1.7;margin:0 0 16px;">Dear {{ $certificate['recipientName'] }},</p>
                                <p style="font-size:15px;line-height:1.7;margin:0 0 16px;">Thank you for participating in {{ $certificate['eventName'] }}.</p>
                                <p style="font-size:15px;line-height:1.7;margin:0 0 22px;">Your certificate is ready and available from the secure link below.</p>
                            @endif

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border-radius:14px;margin-bottom:22px;">
                                <tr>
                                    <td style="padding:16px;">
                                        <div style="font-size:12px;color:#64748b;font-weight:700;">{{ $isArabic ? 'رقم الشهادة' : 'Certificate number' }}</div>
                                        <div style="font-size:18px;color:#0f172a;font-weight:800;margin-top:4px;">{{ $certificate['certificateNumber'] }}</div>
                                    </td>
                                </tr>
                            </table>

                            <a href="{{ $certificate['certificateUrl'] }}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:13px 18px;border-radius:12px;font-size:14px;font-weight:800;">
                                {{ $isArabic ? 'عرض الشهادة' : 'View Certificate' }}
                            </a>

                            <p style="font-size:14px;line-height:1.7;color:#64748b;margin:24px 0 0;">
                                {{ $isArabic ? 'مع أطيب التحيات،' : 'Best regards,' }}<br>
                                {{ $brand }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
