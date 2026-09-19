<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice #{{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 50px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #23262e; }
        .studio-name { font-size: 16px; font-weight: bold; margin-bottom: 4px; }
        .muted { color: #373b45; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .header-table td { vertical-align: top; width: 50%; padding-bottom: 24px; border-bottom: 1px solid #e7e7e9; }
        .header-table td.client { border-left: 1px solid #e7e7e9; padding-left: 16px; }
        .label { font-size: 9px; font-weight: bold; text-transform: uppercase; color: #373b45; margin-bottom: 6px; }
        h1 { font-size: 20px; margin: 0 0 6px; }
        .meta { color: #373b45; margin-bottom: 24px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.items th { text-align: left; font-size: 9px; text-transform: uppercase; color: #373b45; border-bottom: 1px solid #e7e7e9; padding: 6px 0; }
        table.items th.amount, table.items td.amount { text-align: right; }
        table.items td { padding: 10px 0; border-bottom: 1px solid #e7e7e9; vertical-align: top; }
        .item-details { font-size: 9px; color: #373b45; margin-top: 4px; }
        table.totals { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.totals td { padding: 4px 0; }
        table.totals td.amount { text-align: right; }
        table.totals tr.total td { font-weight: bold; font-size: 13px; border-top: 1px solid #23262e; padding-top: 8px; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .status-draft { background: #e7e7e9; color: #373b45; }
        .status-sent { background: #ffe3e9; color: #ff365b; }
        .status-paid { background: #e7efe3; color: #507e40; }
    </style>
</head>
<body>
    <div class="studio-name">{{ $studio->name }}</div>
    @if ($studio->address)
        <div class="muted">{!! nl2br(e($studio->address)) !!}</div>
    @endif
    @if ($studio->email)<div class="muted">{{ $studio->email }}</div>@endif
    @if ($studio->phone)<div class="muted">{{ $studio->phone }}</div>@endif
    @if ($studio->website)<div class="muted">{{ $studio->website }}</div>@endif

    <table class="header-table">
        <tr>
            <td>&nbsp;</td>
            <td class="client">
                <div class="label">Client</div>
                <div><strong>{{ $invoice->company->name }}</strong></div>
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
