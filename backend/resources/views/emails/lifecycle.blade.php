@php
    $fullName = trim((string) ($recipient->name ?? ''));
    $nameParts = preg_split('/\s+/', $fullName);
    $firstName = $fullName !== '' ? ($nameParts[0] ?? $fullName) : 'there';
    $frontendUrl = rtrim((string) config(
        'trustfix.frontend_url',
        'https://trustfix.lakehousesoftware.com'
    ), '/');
    $supportEmail = (string) config('trustfix.support_email');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <title>{{ $subjectLine }}</title>
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
                font-size: 29px !important;
                line-height: 35px !important;
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
        {{ $preheader }}
    </div>
    <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
        &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f4f8fb" style="width: 100%; background-color: #f4f8fb;">
        <tr>
            <td align="center" style="padding: 32px 12px;">
                <table role="presentation" class="email-shell" width="600" cellspacing="0" cellpadding="0" border="0" style="width: 600px; max-width: 600px; background-color: #ffffff; border: 1px solid #d9e2ec;">
                    <tr>
                        <td align="center" bgcolor="#000000" style="padding: 25px 24px 21px; background-color: #000000; border-bottom: 4px solid #4EA8DE;">
                            <a href="{{ $frontendUrl }}" style="display: inline-block; text-decoration: none;">
                                <img
                                    class="brand-logo"
                                    src="{{ $message->embed(public_path('images/trustfix-email-logo.jpg')) }}"
                                    width="190"
                                    alt="TrustFix Technology Corp"
                                    style="display: block; width: 190px; max-width: 100%; height: auto; border: 0;"
                                >
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td class="mobile-pad" style="padding: 42px 48px 16px;">
                            <p style="margin: 0 0 11px; color: #2d6cdf; font-size: 12px; line-height: 18px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;">
                                TrustFix account update
                            </p>
                            <h1 class="mobile-title" style="margin: 0 0 17px; color: #101820; font-size: 34px; line-height: 41px; font-weight: 700; letter-spacing: -0.5px;">
                                {{ $headline }}
                            </h1>
                            <p style="margin: 0 0 14px; color: #52616b; font-size: 16px; line-height: 26px;">
                                Hi {{ $firstName }},
                            </p>
                            <p style="margin: 0; color: #52616b; font-size: 16px; line-height: 26px;">
                                {{ $intro }}
                            </p>
                        </td>
                    </tr>

                    @if (!empty($details))
                        <tr>
                            <td class="mobile-pad" style="padding: 16px 48px 8px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#eaf4ff" style="width: 100%; background-color: #eaf4ff; border-left: 4px solid #4EA8DE;">
                                    @foreach ($details as $label => $value)
                                        <tr>
                                            <td valign="top" style="padding: {{ $loop->first ? '18px' : '7px' }} 18px {{ $loop->last ? '18px' : '7px' }}; color: #52616b; font-size: 13px; line-height: 20px; width: 38%;">
                                                {{ $label }}
                                            </td>
                                            <td valign="top" style="padding: {{ $loop->first ? '18px' : '7px' }} 18px {{ $loop->last ? '18px' : '7px' }} 6px; color: #101820; font-size: 14px; line-height: 20px; font-weight: 700;">
                                                {{ $value }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif

                    @if ($actionLabel && $actionUrl)
                        <tr>
                            <td class="mobile-pad" align="left" style="padding: 28px 48px 16px;">
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        <td align="center" bgcolor="#2d6cdf" style="background-color: #2d6cdf; border-radius: 999px;">
                                            <a href="{{ $actionUrl }}" style="display: inline-block; padding: 14px 26px; color: #ffffff; font-size: 16px; line-height: 20px; font-weight: 700; text-decoration: none; border: 1px solid #2d6cdf; border-radius: 999px;">
                                                {{ $actionLabel }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <tr>
                            <td class="mobile-pad" style="padding: 0 48px 20px;">
                                <p style="margin: 0; color: #7b8790; font-size: 11px; line-height: 18px;">
                                    Button not working? Copy and paste this address into your browser:
                                    <br>
                                    <a href="{{ $actionUrl }}" style="color: #2d6cdf; text-decoration: underline; word-break: break-all;">{{ $actionUrl }}</a>
                                </p>
                            </td>
                        </tr>
                    @endif

                    @if ($notice)
                        <tr>
                            <td class="mobile-pad" style="padding: 8px 48px 38px;">
                                <p style="margin: 0; padding: 14px 16px; color: #52616b; background-color: #f4f8fb; border: 1px solid #d9e2ec; font-size: 13px; line-height: 21px;">
                                    {{ $notice }}
                                </p>
                            </td>
                        </tr>
                    @else
                        <tr>
                            <td style="height: 28px; line-height: 28px;">&nbsp;</td>
                        </tr>
                    @endif

                    <tr>
                        <td class="mobile-pad" bgcolor="#000000" style="padding: 25px 48px 27px; background-color: #000000; border-top: 4px solid #4EA8DE;">
                            <p style="margin: 0 0 7px; color: #ffffff; font-size: 13px; line-height: 20px; font-weight: 700; letter-spacing: 0.8px;">
                                TRUSTFIX TECHNOLOGY CORP
                            </p>
                            <p style="margin: 0 0 12px; color: #b8c4cc; font-size: 12px; line-height: 19px;">
                                Fast, honest, human-centered property repairs.
                            </p>
                            <p style="margin: 0; color: #89959d; font-size: 11px; line-height: 18px;">
                                Questions? Contact
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
