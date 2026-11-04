<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="UTF-8"></head>
<body style="font-family:sans-serif;background:#f8fafc;margin:0;padding:32px 0;">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;border:1px solid #e2e8f0;padding:40px;">
        <tr><td>
          <h1 style="font-size:22px;color:#1e293b;margin:0 0 8px;">{{ config('app.name') }}</h1>
          <h2 style="font-size:16px;color:#475569;font-weight:normal;margin:0 0 24px;">{{ $title }}</h2>
          <p style="color:#334155;line-height:1.6;">{!! nl2br(e($body)) !!}</p>
          <p style="text-align:center;margin:32px 0;">
            <a href="{{ $openUrl }}" style="background:#2563eb;color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:15px;font-weight:600;">{{ __('View') }}</a>
          </p>
          @if ($unsubscribeUrl !== null)
            <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
            <p style="color:#94a3b8;font-size:12px;line-height:1.6;">
              {{ __('You receive this e-mail because this type of notification is enabled in your profile.') }}<br>
              <a href="{{ $unsubscribeUrl }}" style="color:#94a3b8;">{{ __('Stop receiving this type of e-mail') }}</a>
            </p>
          @endif
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
