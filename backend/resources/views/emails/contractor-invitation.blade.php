<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Join TrustFix</title>
</head>
<body style="margin:0;padding:32px 16px;background:#f4f8fb;color:#1f2933;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#fff;border:1px solid #d9e2ec;">
                    <tr>
                        <td style="padding:28px 40px;background:#000;color:#fff;border-bottom:4px solid #4EA8DE;">
                            <strong style="letter-spacing:1px;">TRUSTFIX</strong>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:40px;">
                            <h1 style="margin:0 0 18px;font-size:28px;line-height:34px;color:#101820;">You are invited to join TrustFix</h1>
                            <p style="font-size:16px;line-height:25px;color:#52616b;">Hi {{ $recipientName ?: 'there' }},</p>
                            <p style="font-size:16px;line-height:25px;color:#52616b;">An administrator has invited you to create a contractor profile. Open the secure link below to activate your account and begin the verification process.</p>
                            <p style="margin:30px 0;">
                                <a href="{{ $inviteUrl }}" style="display:inline-block;padding:14px 24px;background:#2d6cdf;color:#fff;border-radius:999px;text-decoration:none;font-weight:700;">Join TrustFix</a>
                            </p>
                            <p style="font-size:13px;line-height:20px;color:#7b8790;">This one-time invitation expires {{ $expiresAt }}. If you were not expecting this invitation, you can safely ignore this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
