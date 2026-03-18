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
                            <h1 style="margin: 0 0 8px; font-size: 22px; font-weight: 600; color: #1a1a2e;">Backorder Cancelled</h1>
                            <p style="margin: 0 0 24px; font-size: 14px; color: #9ca3af;">Order #{{ $orderId }}</p>

                            <p style="margin: 0 0 20px; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                Hi {{ $customerName }},
                            </p>
                            <p style="margin: 0 0 24px; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                We're sorry to let you know that your backorder has been cancelled as the item is no longer available.
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
                                </table>
                            </div>

                            <p style="margin: 0; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                No charges have been made. We apologize for the inconvenience.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 48px; background-color: #f9fafb; border-top: 1px solid #eee;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af;">
                                If you have any questions, please contact us.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
