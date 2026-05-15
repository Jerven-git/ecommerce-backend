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
                            <h1 style="margin: 0 0 8px; font-size: 22px; font-weight: 600; color: #1a1a2e;">You received a Gift Card!</h1>
                            <p style="margin: 0 0 24px; font-size: 14px; color: #9ca3af;">From {{ $senderName }}</p>

                            <p style="margin: 0 0 20px; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                Hi {{ $recipientName }},
                            </p>
                            <p style="margin: 0 0 24px; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                {{ $senderName }} has sent you a gift card worth {{ $currency }} {{ $amount }}.
                            </p>

                            @if ($personalMessage)
                            <div style="background-color: #f9fafb; border-radius: 8px; padding: 20px 24px; border: 1px solid #f0f0f5; margin-bottom: 24px;">
                                <p style="margin: 0 0 8px; font-size: 13px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Personal Message</p>
                                <p style="margin: 0; font-size: 15px; color: #4a4a68; line-height: 1.7; font-style: italic;">"{{ $personalMessage }}"</p>
                            </div>
                            @endif

                            {{-- Gift Card Code --}}
                            <div style="background-color: #f0f4ff; border-radius: 8px; padding: 24px; border: 1px solid #dde4f5; margin-bottom: 24px; text-align: center;">
                                <p style="margin: 0 0 8px; font-size: 13px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Your Gift Card Code</p>
                                <p style="margin: 0; font-size: 28px; font-weight: 700; color: #1a1a2e; letter-spacing: 3px; font-family: monospace;">{{ $code }}</p>
                                <p style="margin: 8px 0 0; font-size: 13px; color: #9ca3af;">Value: {{ $currency }} {{ $amount }}</p>
                            </div>

                            <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.7; color: #4a4a68;">
                                To redeem, enter this code at checkout. Your balance will be applied automatically.
                            </p>

                            <p style="margin: 24px 0 0; font-size: 13px; color: #9ca3af;">
                                Gift cards do not expire. If you have any questions, please contact us.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
