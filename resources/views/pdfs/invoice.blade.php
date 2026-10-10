<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @include('pdfs.partials.styles')
        {{-- Laid out like the proposal: "Invoice" on its own line, then what it's for;
             the number's with the dates, three across. --}}
        table.fields td { width: 33.33%; }
        h1 { margin-bottom: 16px; }
        table.fields { margin-bottom: 24px; }
        table.fields td { padding-bottom: 12px; }
    </style>
</head>
<body>
    <img class="logo" src="{{ $studio->logoPngSrc() }}" alt="{{ $studio->name }}">

    <h1><span class="title-prefix">Invoice</span>{{ $invoice->documentTitle() }}</h1>

    @php
        $company = $invoice->company;
        $cityStateZip = collect([$company->city, trim($company->state.' '.$company->postal_code)])->filter()->implode(', ');
        $terms = $invoice->paymentTermsLabel();
        // Label => [value, muted suffix], laid out two per row below.
        $fields = collect([
            'Invoice number' => [$invoice->invoice_number, null],
            'Issued on' => [$invoice->formattedIssuedOn(), null],
            'Due date' => [$invoice->formattedDueOn(), $terms ? "({$terms})" : null],
            'PO number' => [$invoice->project?->po_number, null],
        ])->filter(fn ($field) => filled($field[0]));
    @endphp
    <table class="details parties">
        <tr>
            <td class="left">
                @include('pdfs.partials.from')
            </td>
            <td>
                <div class="label">Bill to</div>
                @if ($invoice->contact)
                    <div class="party-name">{{ $invoice->contact->name }}</div>
                    <div class="muted">{{ $company->name }}</div>
                @else
                    <div class="party-name">{{ $company->name }}</div>
                @endif
                @if ($company->address_line1)<div class="muted">{{ $company->address_line1 }}</div>@endif
                @if ($cityStateZip)<div class="muted">{{ $cityStateZip }}</div>@endif
                @if ($invoice->contact?->email)<div class="muted">{{ $invoice->contact->email }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="details fields">
        @foreach ($fields->chunk(3) as $row)
            <tr>
                @foreach ($row as $label => [$value, $suffix])
                    <td @class(['left' => ! $loop->last])>
                        <div class="label">{{ $label }}</div>
                        <div class="value">
                            {{ $value }}
                            @if ($suffix)<span class="muted">{{ $suffix }}</span>@endif
                        </div>
                    </td>
                @endforeach
                @for ($i = $row->count(); $i < 3; $i++)<td></td>@endfor
            </tr>
        @endforeach
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="description">Description</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td class="description">
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
        @if ($invoice->hasTax())
            <tr>
                <td class="muted">{{ $invoice->taxLabel() }} on ${{ number_format($invoice->taxableSubtotal(), 2) }}</td>
                <td class="amount muted">${{ number_format($invoice->taxAmount(), 2) }}</td>
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
