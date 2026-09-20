<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice #{{ $invoice->invoice_number }}</title>
    <style>
        {{-- Self-hosted TTFs under resources/fonts/pdf -- dompdf's font
             loader can't decode WOFF2 (no brotli support in this stack) and
             its default chroot only allows paths under the app root, so
             these are separate copies from the browser's /public/webfonts
             set rather than the same files. See resources/fonts/pdf/README
             if these ever need regenerating. --}}
        @font-face { font-family: 'Inter'; src: url('{{ resource_path('fonts/pdf/Inter-Regular.ttf') }}') format('truetype'); font-weight: 400; }
        @font-face { font-family: 'Inter'; src: url('{{ resource_path('fonts/pdf/Inter-Medium.ttf') }}') format('truetype'); font-weight: 500; }
        @font-face { font-family: 'Inter'; src: url('{{ resource_path('fonts/pdf/Inter-SemiBold.ttf') }}') format('truetype'); font-weight: 600; }
        @font-face { font-family: 'Inter'; src: url('{{ resource_path('fonts/pdf/Inter-Bold.ttf') }}') format('truetype'); font-weight: 700; }
        @font-face { font-family: 'Inter Display'; src: url('{{ resource_path('fonts/pdf/InterDisplay-Medium.ttf') }}') format('truetype'); font-weight: 500; }
        @font-face { font-family: 'Inter Display'; src: url('{{ resource_path('fonts/pdf/InterDisplay-SemiBold.ttf') }}') format('truetype'); font-weight: 600; }
        @font-face { font-family: 'Inter Display'; src: url('{{ resource_path('fonts/pdf/InterDisplay-Bold.ttf') }}') format('truetype'); font-weight: 700; }
        @font-face { font-family: 'Inter Display'; src: url('{{ resource_path('fonts/pdf/InterDisplay-ExtraBold.ttf') }}') format('truetype'); font-weight: 800; }

        @page { margin: 1in; }
        body { font-family: 'Inter', sans-serif; font-size: 11px; color: #23262e; }
        .logo { width: 130px; height: auto; margin-bottom: 20px; }
        .studio-name { font-weight: 500; margin-bottom: 4px; }
        .muted { color: #595F64; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .header-table td { vertical-align: top; width: 50%; padding-bottom: 24px; border-bottom: 1px solid #e7e7e9; }
        .header-table td.client { border-left: 1px solid #e7e7e9; padding-left: 16px; }
        h1 { font-family: 'Inter Display', sans-serif; font-weight: 800; letter-spacing: -0.02em; font-size: 22px; margin: 0 0 6px; }
        .meta { color: #595F64; margin-bottom: 24px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.items th { text-align: left; font-size: 9px; font-weight: 600; text-transform: uppercase; color: #595F64; border-bottom: 1px solid #e7e7e9; padding: 6px 0; }
        table.items th.amount, table.items td.amount { text-align: right; }
        table.items td { padding: 10px 0; border-bottom: 1px solid #e7e7e9; vertical-align: top; }
        .item-details { font-size: 9px; color: #595F64; margin-top: 4px; }
        table.totals { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.totals td { padding: 4px 0; }
        table.totals td.amount { text-align: right; }
        table.totals tr.total td { font-weight: 700; font-size: 13px; border-top: 1px solid #23262e; padding-top: 8px; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 9px; font-weight: 700; text-transform: uppercase; }
        .status-draft { background: #e7e7e9; color: #595F64; }
        .status-sent { background: #ffe3e9; color: #ff365b; }
        .status-paid { background: #e7efe3; color: #5AA329; }
    </style>
</head>
<body>
    <img class="logo" src="{{ public_path('images/studio-lockup.png') }}" alt="{{ $studio->name }}">

    <table class="header-table">
        <tr>
            <td>
                <div class="studio-name"><strong>{{ $studio->name }}</strong></div>
                @if ($studio->address)
                    <div class="muted">{!! nl2br(e($studio->address)) !!}</div>
                @endif
                @if ($studio->email)<div class="muted">{{ $studio->email }}</div>@endif
                @if ($studio->phone)<div class="muted">{{ $studio->phone }}</div>@endif
                @if ($studio->website)<div class="muted">{{ $studio->website }}</div>@endif
            </td>
            <td class="client">
                <div class="studio-name"><strong>{{ $invoice->company->name }}</strong></div>
                @if ($invoice->project)
                    <div class="muted">{{ $invoice->project->name }}</div>
                @endif
                @if ($invoice->project?->po_number)
                    <div class="muted">PO #{{ $invoice->project->po_number }}</div>
                @endif
            </td>
        </tr>
    </table>

    <h1>Invoice #{{ $invoice->invoice_number }}</h1>
    <div class="meta">
        Issued {{ $invoice->issued_on->format('M j, Y') }} &middot; Due {{ $invoice->due_on->format('M j, Y') }}
        @if ($invoice->contact) &middot; Billed to {{ $invoice->contact->name }} @endif
        &middot; <span class="status status-{{ $invoice->status }}">{{ ucfirst($invoice->status) }}</span>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>
                        {{ $item->description }}
                        @if ($item->details && $item->details !== $item->description)
                            <div class="item-details">{{ $item->details }}</div>
                        @endif
                    </td>
                    <td class="amount">${{ number_format($item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">Subtotal</td>
            <td class="amount muted">${{ number_format($invoice->subtotal(), 2) }}</td>
        </tr>
        @if ($invoice->surcharge)
            <tr>
                <td class="muted">Card processing fee (3%)</td>
                <td class="amount muted">${{ number_format($invoice->surchargeAmount(), 2) }}</td>
            </tr>
        @endif
        <tr class="total">
            <td>Total</td>
            <td class="amount">${{ number_format($invoice->total(), 2) }}</td>
        </tr>
    </table>

    @if ($invoice->payments->isNotEmpty())
        <table class="items" style="margin-top: 24px;">
            <thead>
                <tr>
                    <th>Payments</th>
                    <th class="amount">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_at->format('M j, Y') }}</td>
                        <td class="amount">${{ number_format($payment->amount + $payment->surcharge_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
