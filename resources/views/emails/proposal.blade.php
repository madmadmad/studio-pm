<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $proposal->company->name }} Proposal: {{ $proposal->title }}</title>
    {{-- Inter Display where the mail app loads web fonts (Apple Mail, iOS,
         Outlook for Mac); Gmail and Outlook for Windows drop it and fall
         back to Helvetica/Arial. --}}
    @php($font = "'Inter Display', Helvetica, Arial, sans-serif")
    <style>
        @font-face { font-family: 'Inter Display'; src: url('{{ url('/webfonts/InterDisplay/InterDisplay-Regular.woff2') }}') format('woff2'); font-weight: 400; font-style: normal; }
        @font-face { font-family: 'Inter Display'; src: url('{{ url('/webfonts/InterDisplay/InterDisplay-SemiBold.woff2') }}') format('woff2'); font-weight: 600; font-style: normal; }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: {!! $font !!};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background-color:#ffffff; border-radius:8px; overflow:hidden; font-family: {!! $font !!};">
                    <tr>
                        <td style="padding: 32px 32px 0;">
                            <img src="{{ $studio->logoPngUrl() }}" alt="{{ $studio->name }}" width="180" style="display:block; margin-bottom: 28px;">

                            <div style="font-size:14px; line-height:22px; color:#23262e; white-space: pre-line;">{{ $body }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px 32px 32px;">
                            <a href="{{ $viewUrl }}" style="display:inline-block; font-family: {!! $font !!}; background-color:#23262e; color:#ffffff; text-decoration:none; font-size:14px; font-weight:600; padding:12px 28px; border-radius:6px;">
                                Review Proposal
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
