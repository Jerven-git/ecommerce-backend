<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f4f4f7; margin: 0; padding: 0;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f7; padding: 40px 0;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="padding: 40px 48px;">
                            <h1 style="margin: 0 0 24px; font-size: 22px; font-weight: 600; color: #1a1a2e;">Verification Code</h1>
                            <p style="margin: 0 0 24px; font-size: 15px; line-height: 1.6; color: #4a4a68;">
                                Hi {{ $userName }}, use the code below to complete your login:
                            </p>
                            <div style="text-align: center; margin: 32px 0;">
                                <span style="display: inline-block; font-size: 36px; font-weight: 700; letter-spacing: 8px; color: #1a1a2e; background-color: #f0f4ff; padding: 16px 32px; border-radius: 8px; border: 1px solid #e0e7ff;">{{ $code }}</span>
                            </div>
                            <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.6; color: #6b6b80;">
                                This code expires in <strong>10 minutes</strong>.
                            </p>
                            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #6b6b80;">
                                If you did not attempt to log in, please ignore this email or contact support if you have concerns.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 48px; background-color: #f9fafb; border-top: 1px solid #eee;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af;">
                                Do not share this code with anyone. Our team will never ask for your verification code.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
