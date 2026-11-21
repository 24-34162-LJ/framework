{{--
    FILE PURPOSE
    File   : resources/views/emails/notification.blade.php
    Feature: Notifications
    Purpose: HTML template of the notification email.
--}}

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>

<body style="margin:0;padding:24px 12px;background:#f3f5f8;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
        style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #dde3ea;border-radius:8px;">
        <tr>
            <td style="padding:20px 24px;border-bottom:1px solid #dde3ea;font-size:16px;font-weight:bold;color:#1e3a5f;">AstraTrack</td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <h1 style="margin:0 0 12px;font-size:18px;color:#1f2933;">{{ $subjectLine }}</h1>
                <p style="margin:0 0 20px;font-size:15px;line-height:1.5;">{{ $body }}</p>
                @if ($link)
                    <a href="{{ $link }}"
                        style="display:inline-block;padding:10px 18px;background:#1e3a5f;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;">{{ $linkLabel }}</a>
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding:16px 24px;border-top:1px solid #dde3ea;font-size:12px;color:#6b7785;">This is an automatic message from
                AstraTrack. Please do not reply to this email.</td>
        </tr>
    </table>
</body>

</html>
