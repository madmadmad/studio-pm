<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @include('pdfs.partials.styles')
        {{-- Invoices keep the title on one line, "Invoice 1004", both at
             .document__title--large's size (2rem, scaled). --}}
        h1 { margin-bottom: 16px; font-size: 25px; line-height: 27px; }
        .title-prefix { display: inline; font-size: inherit; line-height: inherit; }
        table.fields { margin-bottom: 24px; }
        table.fields td { padding-bottom: 12px; }
    </style>
</head>
<body>
    <img class="logo" src="{{ public_path('images/studio-lockup.png') }}" alt="{{ $studio->name }}">

    <h1><span class="title-prefix">Invoice </span>{{ $invoice->invoice_number }}</h1>

    @php
        $company = $invoice->company;
        $cityStateZip = collect([$company->city, trim($company->state.' '.$company->postal_code)])->filter()->implode(', ');
        $terms = $invoice->paymentTermsLabel();
        // Label => [value, muted suffix], laid out two per row below.
        $fields = collect([
            'Issued on' => [$invoice->formattedIssuedOn(), null],
            'Due date' => [$invoice->formattedDueOn(), $terms ? "({$terms})" : null],
            'Project' => [$invoice->project?->name, null],
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
        @foreach ($fields->chunk(2) as $row)
            <tr>
                @foreach ($row as $label => [$value, $suffix])
                    <td @class(['left' => $loop->first])>
                        <div class="label">{{ $label }}</div>
                        <div class="value">
                            {{ $value }}
                            @if ($suffix)<span class="muted">{{ $suffix }}</span>@endif
                        </div>
                    </td>
                @endforeach
                @if ($row->count() === 1)<td></td>@endif
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
