<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $renderedSubject }}</title>
</head>
<body style="margin:0;padding:24px;background:#f5f7fb;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1e293b;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;border:1px solid #e2e8f0;">
        <tr>
            <td style="padding:28px 32px;font-size:15px;line-height:1.6;">
                {{-- Already HTML-escaped by App\Services\TemplateRenderer. --}}
                {!! $htmlBody !!}
            </td>
        </tr>
        <tr>
            <td style="padding:16px 32px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
                Sent via RBEL-CRM · Financial Advisor Client Management
            </td>
        </tr>
    </table>
</body>
</html>
