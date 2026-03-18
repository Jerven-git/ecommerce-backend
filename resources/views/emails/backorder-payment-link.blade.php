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
                            <h1 style="margin: 0 0 8px; font-size: 22px; font-weight: 600; color: #1a1a2e;">Your Item is Available!</h1>
                            <p style="margin: 0 0 24px; font-size: 14px; color: #9ca3af;">Order #{{ $orderId }}</p>

                            <p style="margin: 0 0 20px; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                Hi {{ $customerName }},
                            </p>
                            <p style="margin: 0 0 24px; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                Great news! The item you backordered is now in stock and ready for you.
                            </p>

                            <div style="background-color: #f9fafb; border-radius: 8px; padding: 20px 24px; border: 1px solid #f0f0f5; margin-bottom: 24px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding: 6px 0; font-size: 13px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; vertical-align: top;">Product</td>
                                        <td style="padding: 6px 0; font-size: 15px; color: #1a1a2e; font-weight: 600;">{{ $productName }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0; font-size: 13px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; vertical-align: top;">Quantity</td>
                                        <td style="padding: 6px 0; font-size: 15px; color: #1a1a2e;">{{ $quantity }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0; font-size: 13px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; vertical-align: top;">Total</td>
                                        <td style="padding: 6px 0; font-size: 15px; color: #1a1a2e; font-weight: 600;">${{ $price }}</td>
                                    </tr>
                                </table>
                            </div>

                            <p style="margin: 0 0 24px; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                Please complete your payment within <strong>{{ $expiryHours }} hours</strong> to secure your item.
                            </p>

                            <div style="text-align: center; margin-bottom: 24px;">
                                <a href="{{ $paymentUrl }}" style="display: inline-block; padding: 14px 32px; background-color: #4B5979; color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 15px; font-weight: 600;">
                                    Complete Payment
                                </a>
                            </div>

                            <p style="margin: 0; font-size: 13px; line-height: 1.6; color: #9ca3af;">
                                If the button doesn't work, copy and paste this link into your browser:<br>
                                <a href="{{ $paymentUrl }}" style="color: #4B5979; word-break: break-all;">{{ $paymentUrl }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 48px; background-color: #f9fafb; border-top: 1px solid #eee;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af;">
                                This link will expire in {{ $expiryHours }} hours. If you no longer wish to purchase this item, you can ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
