<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $invoice->company->name }} Invoice #{{ $invoice->invoice_number }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background-color:#ffffff; border-radius:8px; overflow:hidden;">
                    <tr>
                        <td style="padding: 32px 32px 0;">
                            <img src="{{ url('/images/studio-lockup.png') }}" alt="{{ $studio->name }}" width="110" style="display:block; margin-bottom: 20px;">

                            @if ($reminderLabel)
                                <div style="display:inline-block; background-color:#fdecea; color:#b3261e; font-size:12px; font-weight:600; padding:4px 10px; border-radius:999px; margin-bottom:16px;">
                                    {{ $reminderLabel }}
                                </div>
                            @endif

                            <div style="font-size:14px; line-height:22px; color:#23262e; white-space: pre-line;">{{ $body }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9f9fa; border-radius:6px; padding: 16px;">
                                <tr>
                                    <td style="font-size:13px; color:#595F64; padding-bottom:6px;">Invoice</td>
                                    <td style="font-size:13px; color:#595F64; padding-bottom:6px; text-align:right;">#{{ $invoice->invoice_number }}</td>
                                </tr>
                                <tr>
                                    <td style="font-size:13px; color:#595F64; padding-bottom:6px;">Amount due</td>
                                    <td style="font-size:13px; color:#23262e; font-weight:700; padding-bottom:6px; text-align:right;">${{ number_format($amountDue, 2) }}</td>
                                </tr>
                                <tr>
                                    <td style="font-size:13px; color:#595F64; padding-bottom:6px;">Issued</td>
                                    <td style="font-size:13px; color:#595F64; padding-bottom:6px; text-align:right;">{{ $invoice->formattedIssuedOn() }}</td>
                                </tr>
                                <tr>
                                    <td style="font-size:13px; color:#595F64;">Due</td>
                                    <td style="font-size:13px; color:#595F64; text-align:right;">{{ $invoice->formattedDueOn() }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 0 32px 32px;">
                            <a href="{{ $payUrl }}" style="display:inline-block; background-color:#23262e; color:#ffffff; text-decoration:none; font-size:14px; font-weight:600; padding:12px 28px; border-radius:6px;">
                                View &amp; pay invoice
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 32px 32px; font-size:12px; color:#8a8f94;">
                            The invoice is attached to this email as a PDF.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
