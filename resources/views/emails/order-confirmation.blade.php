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
                            <h1 style="margin: 0 0 8px; font-size: 22px; font-weight: 600; color: #1a1a2e;">Order Confirmed</h1>
                            <p style="margin: 0 0 24px; font-size: 14px; color: #9ca3af;">Order #{{ $orderId }}</p>

                            <p style="margin: 0 0 20px; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                Hi {{ $customerName }},
                            </p>
                            <p style="margin: 0 0 24px; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                Thank you for your order! Your payment has been confirmed and we're getting everything ready.
                            </p>

                            {{-- Order items --}}
                            <div style="background-color: #f9fafb; border-radius: 8px; padding: 20px 24px; border: 1px solid #f0f0f5; margin-bottom: 24px;">
                                <p style="margin: 0 0 12px; font-size: 13px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Items Ordered</p>
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    @foreach ($items as $item)
                                    <tr>
                                        <td style="padding: 8px 0; font-size: 14px; color: #1a1a2e; border-bottom: 1px solid #f0f0f5;">
                                            {{ $item->product_name }} <span style="color: #9ca3af;">&times; {{ $item->quantity }}</span>
                                        </td>
                                        <td style="padding: 8px 0; font-size: 14px; color: #1a1a2e; text-align: right; border-bottom: 1px solid #f0f0f5; font-weight: 500;">
                                            ${{ number_format((float) $item->subtotal, 2) }}
                                        </td>
                                    </tr>
                                    @endforeach
                                </table>

                                {{-- Totals --}}
                                <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 12px;">
                                    <tr>
                                        <td style="padding: 4px 0; font-size: 13px; color: #6b7280;">Subtotal</td>
                                        <td style="padding: 4px 0; font-size: 13px; color: #6b7280; text-align: right;">${{ $subtotal }}</td>
                                    </tr>
                                    @if ($hasDiscount)
                                    <tr>
                                        <td style="padding: 4px 0; font-size: 13px; color: #059669;">Discount</td>
                                        <td style="padding: 4px 0; font-size: 13px; color: #059669; text-align: right;">-${{ $discountAmount }}</td>
                                    </tr>
                                    @endif
                                    <tr>
                                        <td style="padding: 4px 0; font-size: 13px; color: #6b7280;">Tax</td>
                                        <td style="padding: 4px 0; font-size: 13px; color: #6b7280; text-align: right;">${{ $taxAmount }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 4px 0; font-size: 13px; color: #6b7280;">Shipping</td>
                                        <td style="padding: 4px 0; font-size: 13px; color: #6b7280; text-align: right;">${{ $shippingAmount }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0 0; font-size: 15px; font-weight: 600; color: #1a1a2e; border-top: 1px solid #e5e7eb;">Total</td>
                                        <td style="padding: 8px 0 0; font-size: 15px; font-weight: 600; color: #1a1a2e; text-align: right; border-top: 1px solid #e5e7eb;">${{ $totalAmount }}</td>
                                    </tr>
                                </table>
                            </div>

                            {{-- Shipping info --}}
                            @if ($shippingAddress)
                            <div style="background-color: #f9fafb; border-radius: 8px; padding: 20px 24px; border: 1px solid #f0f0f5; margin-bottom: 24px;">
                                <p style="margin: 0 0 8px; font-size: 13px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                                    {{ $deliveryMethod === 'pickup' ? 'Pickup' : 'Shipping Address' }}
                                </p>
                                <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #4a4a68;">{{ $shippingAddress }}</p>
                            </div>
                            @endif

                            <p style="margin: 0; font-size: 15px; line-height: 1.7; color: #4a4a68;">
                                We'll notify you when your order ships. If you have any questions, feel free to reach out.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 48px; background-color: #f9fafb; border-top: 1px solid #eee;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af;">
                                This is an automated confirmation for your order. Please keep this email for your records.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
