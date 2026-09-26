@php use App\Support\RichText; @endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Proposal: {{ $proposal->title }}</title>
    <style>
        @include('pdfs.partials.styles')
        {{-- Mirrors the public proposal page (.document__title--spaced,
             .document__body, .prose, .document__section-title in
             _document.scss / _prose.scss), scaled like the title. --}}
        h1 { margin-bottom: 16px; }
        table.parties { margin-bottom: 24px; }
        .estimate { color: #595F64; margin-bottom: 19px; }
        .body { line-height: 1.6; margin-bottom: 19px; padding-bottom: 19px; border-bottom: 1px solid #e7e7e9; }
        .body p { margin: 0 0 0.75em; }
        .body ul, .body ol { margin: 0.5em 0 0.75em 1.25em; padding: 0; }
        .body h2, .body h3, .body h4 { font-family: 'Inter Display', sans-serif; font-weight: 800; letter-spacing: -0.02em; font-size: 11px; margin: 0.75em 0 0.4em; }
        {{-- Item notes keep the invoice's tight plain-text spacing. --}}
        .item-details p { margin: 0; }
        .item-details ul, .item-details ol { margin: 0 0 0 1.25em; padding: 0; }
        .section-title { font-family: 'Inter Display', sans-serif; font-weight: 800; letter-spacing: -0.02em; font-size: 14px; margin-bottom: 8px; }
        .notice { margin-top: 24px; color: #595F64; }
    </style>
</head>
<body>
    <img class="logo" src="{{ public_path('images/studio-lockup.png') }}" alt="{{ $studio->name }}">

    <h1><span class="title-prefix">Proposal</span>{{ $proposal->title }}</h1>

    <table class="details parties">
        <tr>
            <td class="left">
                @include('pdfs.partials.from')
            </td>
            <td>
                <div class="label">Client</div>
                <div class="party-name">{{ $proposal->company->name }}</div>
                @if ($proposal->project)
                    <div class="muted">{{ $proposal->project->name }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if ($proposal->items->isEmpty() && $proposal->estimate_amount)
        <div class="estimate">Estimate: ${{ number_format($proposal->estimate_amount, 2) }}</div>
    @endif

    {{-- Editor HTML, sanitized down to the toolbar's own tags. --}}
    <div class="body">{!! RichText::toSafeHtml($proposal->body) !!}</div>

    @if ($proposal->items->isNotEmpty())
        <div class="section-title">Estimate</div>
        <table class="items">
            <thead>
                <tr>
                    <th class="description">Items</th>
                    <th class="amount">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($proposal->items as $item)
                    <tr>
                        <td class="description">
                            {{ $item->description }}
                            @if (! RichText::isBlank($item->details) && RichText::toPlainText($item->details) !== $item->description)
                                <div class="item-details">{!! RichText::toSafeHtml($item->details) !!}</div>
                            @endif
                        </td>
                        <td class="amount">${{ number_format($item->quantity * $item->rate, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr class="total">
                <td>Total</td>
                <td class="amount">${{ number_format($proposal->itemsTotal(), 2) }}</td>
            </tr>
        </table>
    @endif

    @if ($proposal->status === 'accepted')
        <div class="notice">
            Accepted{{ $proposal->accepted_at ? ' on '.$proposal->accepted_at->format('M j, Y') : '' }}.
        </div>
    @endif
</body>
</html>
