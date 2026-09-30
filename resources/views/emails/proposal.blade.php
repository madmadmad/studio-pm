<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $proposal->company->name }} Proposal: {{ $proposal->title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background-color:#ffffff; border-radius:8px; overflow:hidden;">
                    <tr>
                        <td style="padding: 32px 32px 0;">
                            <img src="{{ url('/images/studio-lockup.png') }}" alt="{{ $studio->name }}" width="110" style="display:block; margin-bottom: 20px;">

                            <div style="font-size:14px; line-height:22px; color:#23262e; white-space: pre-line;">{{ $body }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9f9fa; border-radius:6px; padding: 16px;">
                                <tr>
                                    <td style="font-size:13px; color:#595F64; padding-bottom:6px;">Proposal</td>
                                    <td style="font-size:13px; color:#23262e; padding-bottom:6px; text-align:right;">{{ $proposal->title }}</td>
                                </tr>
                                @if ($proposal->project)
                                    <tr>
                                        <td style="font-size:13px; color:#595F64; padding-bottom:6px;">Project</td>
                                        <td style="font-size:13px; color:#595F64; padding-bottom:6px; text-align:right;">{{ $proposal->project->name }}</td>
                                    </tr>
                                @endif
                                @if ($proposal->estimate_amount)
                                    <tr>
                                        <td style="font-size:13px; color:#595F64;">Estimate</td>
                                        <td style="font-size:13px; color:#23262e; font-weight:700; text-align:right;">${{ number_format($proposal->estimate_amount, 2) }}</td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 0 32px 32px;">
                            <a href="{{ $viewUrl }}" style="display:inline-block; background-color:#23262e; color:#ffffff; text-decoration:none; font-size:14px; font-weight:600; padding:12px 28px; border-radius:6px;">
                                Review &amp; accept proposal
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 32px 32px; font-size:12px; color:#8a8f94;">
                            The proposal is attached to this email as a PDF.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
