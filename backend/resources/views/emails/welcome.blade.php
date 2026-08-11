@php
    $fullName = trim((string) ($user->name ?? ''));
    $nameParts = preg_split('/\s+/', $fullName);
    $firstName = $fullName !== '' ? ($nameParts[0] ?? $fullName) : 'there';
    $role = (string) ($user->role ?? 'customer');
    $isContractor = in_array($role, ['handyman', 'company'], true);
    $frontendUrl = rtrim((string) config('trustfix.frontend_url'), '/');
    $loginUrl = $frontendUrl . '/login.php';
    $supportEmail = (string) config('trustfix.support_email');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <title>Welcome to TrustFix</title>
    <!--[if mso]>
    <style>
        table { border-collapse: collapse; }
        td, p, a, h1, h2 { font-family: Arial, Helvetica, sans-serif !important; }
    </style>
    <![endif]-->
    <style>
        @media only screen and (max-width: 620px)
        {
            .email-shell
            {
                width: 100% !important;
            }

            .mobile-pad
            {
                padding-left: 24px !important;
                padding-right: 24px !important;
            }

            .mobile-title
            {
                font-size: 30px !important;
                line-height: 36px !important;
            }

            .brand-logo
            {
                width: 180px !important;
                height: auto !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f8fb; color: #1f2933; font-family: Arial, Helvetica, sans-serif;">
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: transparent; mso-hide: all;">
        Your TrustFix account is ready. Keep every repair detail together and move forward with confidence.
    </div>
    <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
        &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f4f8fb" style="width: 100%; background-color: #f4f8fb;">
        <tr>
            <td align="center" style="padding: 32px 12px;">
                <table role="presentation" class="email-shell" width="600" cellspacing="0" cellpadding="0" border="0" style="width: 600px; max-width: 600px; background-color: #ffffff; border: 1px solid #d9e2ec;">
                    <tr>
                        <td align="center" bgcolor="#000000" style="padding: 28px 24px 24px; background-color: #000000; border-bottom: 4px solid #4EA8DE;">
                            <a href="{{ $frontendUrl }}" style="display: inline-block; text-decoration: none;">
                                <img
                                    class="brand-logo"
                                    src="{{ $message->embed(public_path('images/trustfix-email-logo.jpg')) }}"
                                    width="210"
                                    alt="TrustFix Technology Corp"
                                    style="display: block; width: 210px; max-width: 100%; height: auto; border: 0;"
                                >
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td class="mobile-pad" style="padding: 44px 48px 18px;">
                            <p style="margin: 0 0 12px; color: #2d6cdf; font-size: 12px; line-height: 18px; font-weight: 700; letter-spacing: 1.6px; text-transform: uppercase;">
                                Your account is ready
                            </p>
                            <h1 class="mobile-title" style="margin: 0 0 18px; color: #101820; font-size: 36px; line-height: 43px; font-weight: 700; letter-spacing: -0.6px;">
                                Welcome to TrustFix, {{ $firstName }}.
                            </h1>
                            <p style="margin: 0; color: #52616b; font-size: 17px; line-height: 28px;">
                                TrustFix brings the repair process into one clear workspace&mdash;from the first request through the final fix.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td class="mobile-pad" style="padding: 10px 48px 26px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#eaf4ff" style="width: 100%; background-color: #eaf4ff; border-left: 4px solid #4EA8DE;">
                                <tr>
                                    <td style="padding: 20px 22px;">
                                        <p style="margin: 0 0 6px; color: #101820; font-size: 16px; line-height: 23px; font-weight: 700;">
                                            {{ $isContractor ? 'Start building your professional presence' : 'Ready when your property needs attention' }}
                                        </p>
                                        <p style="margin: 0; color: #52616b; font-size: 15px; line-height: 24px;">
                                            @if ($isContractor)
                                                Complete your contractor profile and add your credentials so customers can understand your services and hire with confidence.
                                            @else
                                                Add your property, describe the work, and include photos. TrustFix keeps the job organized from estimate to completion.
                                            @endif
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="mobile-pad" style="padding: 6px 48px 8px;">
                            <h2 style="margin: 0 0 22px; color: #101820; font-size: 21px; line-height: 28px; font-weight: 700;">
                                A simpler path to a trusted fix
                            </h2>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%;">
                                <tr>
                                    <td width="42" valign="top" style="width: 42px; padding: 0 14px 22px 0;">
                                        <table role="presentation" width="34" cellspacing="0" cellpadding="0" border="0" bgcolor="#2d6cdf" style="width: 34px; background-color: #2d6cdf; border-radius: 17px;">
                                            <tr>
                                                <td align="center" height="34" style="height: 34px; color: #ffffff; font-size: 14px; font-weight: 700;">1</td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td valign="top" style="padding: 1px 0 22px;">
                                        <p style="margin: 0 0 4px; color: #1f2933; font-size: 16px; line-height: 23px; font-weight: 700;">
                                            Start with the details
                                        </p>
                                        <p style="margin: 0; color: #65737e; font-size: 14px; line-height: 22px;">
                                            Keep property information, job descriptions, and photos in one place.
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="42" valign="top" style="width: 42px; padding: 0 14px 22px 0;">
                                        <table role="presentation" width="34" cellspacing="0" cellpadding="0" border="0" bgcolor="#2d6cdf" style="width: 34px; background-color: #2d6cdf; border-radius: 17px;">
                                            <tr>
                                                <td align="center" height="34" style="height: 34px; color: #ffffff; font-size: 14px; font-weight: 700;">2</td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td valign="top" style="padding: 1px 0 22px;">
                                        <p style="margin: 0 0 4px; color: #1f2933; font-size: 16px; line-height: 23px; font-weight: 700;">
                                            Work with confidence
                                        </p>
                                        <p style="margin: 0; color: #65737e; font-size: 14px; line-height: 22px;">
                                            Connect through a transparent process built around clear information and trusted professionals.
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="42" valign="top" style="width: 42px; padding: 0 14px 0 0;">
                                        <table role="presentation" width="34" cellspacing="0" cellpadding="0" border="0" bgcolor="#2d6cdf" style="width: 34px; background-color: #2d6cdf; border-radius: 17px;">
                                            <tr>
                                                <td align="center" height="34" style="height: 34px; color: #ffffff; font-size: 14px; font-weight: 700;">3</td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td valign="top" style="padding: 1px 0 0;">
                                        <p style="margin: 0 0 4px; color: #1f2933; font-size: 16px; line-height: 23px; font-weight: 700;">
                                            Track every fix
                                        </p>
                                        <p style="margin: 0; color: #65737e; font-size: 14px; line-height: 22px;">
                                            Follow estimates, messages, progress, and records without losing the thread.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="mobile-pad" align="left" style="padding: 36px 48px 18px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" bgcolor="#2d6cdf" style="background-color: #2d6cdf; border-radius: 999px;">
                                        <a href="{{ $loginUrl }}" style="display: inline-block; padding: 14px 26px; color: #ffffff; font-size: 16px; line-height: 20px; font-weight: 700; text-decoration: none; border: 1px solid #2d6cdf; border-radius: 999px;">
                                            Open TrustFix
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="mobile-pad" style="padding: 0 48px 44px;">
                            <p style="margin: 0; color: #7b8790; font-size: 12px; line-height: 19px;">
                                Button not working? Copy and paste this address into your browser:
                                <br>
                                <a href="{{ $loginUrl }}" style="color: #2d6cdf; text-decoration: underline; word-break: break-all;">{{ $loginUrl }}</a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td class="mobile-pad" bgcolor="#000000" style="padding: 26px 48px 28px; background-color: #000000; border-top: 4px solid #4EA8DE;">
                            <p style="margin: 0 0 7px; color: #ffffff; font-size: 13px; line-height: 20px; font-weight: 700; letter-spacing: 0.8px;">
                                TRUSTFIX TECHNOLOGY CORP
                            </p>
                            <p style="margin: 0 0 12px; color: #b8c4cc; font-size: 12px; line-height: 19px;">
                                Fast, honest, human-centered property repairs.
                            </p>
                            <p style="margin: 0; color: #89959d; font-size: 11px; line-height: 18px;">
                                This email was sent because an account was created using this address.
                                If that was not you, contact
                                <a href="mailto:{{ $supportEmail }}" style="color: #4EA8DE; text-decoration: underline;">{{ $supportEmail }}</a>.
                            </p>
                            <p style="margin: 12px 0 0; color: #89959d; font-size: 11px; line-height: 18px;">
                                &copy; {{ date('Y') }} TrustFix. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
