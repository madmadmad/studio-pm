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
        table.parties { margin-bottom: 12px; }
        table.fields { margin-bottom: 24px; }
        .estimate { color: #595F64; margin-bottom: 19px; }
        .disclaimer { color: #595F64; font-size: 9.5px; line-height: 1.5; margin-bottom: 19px; white-space: pre-line; }
        .body { line-height: 1.6; margin-bottom: 26px; padding-bottom: 26px; border-bottom: 1px solid #e7e7e9; }
        .body p { margin: 0 0 0.75em; }
        .body ul, .body ol { margin: 0.5em 0 0.75em 1.25em; padding: 0; }
        {{-- Headings match the web page's: the scope's H2 and the section
             titles share one style (_prose.scss's 1.35em, on this sheet's 11px). --}}
        .body h2, .body h3, .body h4 { font-family: 'Inter Display', sans-serif; font-weight: 600; letter-spacing: -0.018em; margin: 0.75em 0 0.4em; }
        .body h2 { font-size: 15px; }
        .body h3 { font-size: 12.5px; }
        .body h4 { font-size: 11px; }
        {{-- Item notes keep the invoice's tight plain-text spacing. --}}
        .item-details p { margin: 0; }
        .item-details ul, .item-details ol { margin: 0 0 0 1.25em; padding: 0; }
        .section-title { font-family: 'Inter Display', sans-serif; font-weight: 600; letter-spacing: -0.018em; font-size: 15px; margin-bottom: 10px; }
        .notice { margin-top: 24px; color: #595F64; }
        {{-- The team section: a row per person, the portrait (4:5, the page's
             200px scaled like the rest of this sheet) beside name, position and bio. --}}
        table.member { width: 100%; margin-bottom: 14px; page-break-inside: avoid; }
        table.member td { vertical-align: top; }
        td.member-photo { width: 135px; padding-right: 18px; }
        td.member-photo img { width: 135px; height: 169px; border-radius: 3px; }
        {{-- No photo: their initials in the same frame, as on the web page. --}}
        .member-initials { width: 135px; height: 93px; padding-top: 76px; line-height: 24px; border-radius: 3px; background: #e7e7e9; color: #595F64; text-align: center; font-weight: 600; font-size: 22px; }
        .member-name { font-weight: 600; }
        .member-title { color: #595F64; margin-bottom: 4px; }
        .member-bio { line-height: 1.55; }
        .member-bio p { margin: 0 0 0.6em; }
        .member-bio ul, .member-bio ol { margin: 0.3em 0 0.6em 1.25em; padding: 0; }
        {{-- The team and the About each under a rule, with the same room either side as the scope's. --}}
        .team, .about { margin-top: 26px; padding-top: 26px; border-top: 1px solid #e7e7e9; }
        .about { page-break-inside: avoid; }
        .about-body { color: #595F64; line-height: 1.6; }
        .about-body p { margin: 0 0 0.6em; }
    </style>
</head>
<body>
    <img class="logo" src="{{ $studio->logoPngFile() }}" alt="{{ $studio->name }}">

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

    {{-- When it's from, and how long it's good for (until it's accepted). --}}
    <table class="details fields">
        <tr>
            <td class="left">
                <div class="label">Date</div>
                <div class="value">{{ $proposal->documentDate()->format('M j, Y') }}</div>
            </td>
            <td>
                @if ($proposal->status === 'accepted' && $proposal->accepted_at)
                    <div class="label">Accepted</div>
                    <div class="value">{{ $proposal->accepted_at->format('M j, Y') }}</div>
                @else
                    <div class="label">Valid until</div>
                    <div class="value">{{ $proposal->validUntil()->format('M j, Y') }} <span class="muted">({{ config('proposals.valid_days') }} days)</span></div>
                @endif
            </td>
        </tr>
    </table>

    @if ($proposal->items->isEmpty() && $proposal->estimate_amount)
        <div class="estimate">Estimate: ${{ number_format($proposal->estimate_amount, 2) }}</div>
    @endif

    {{-- Editor HTML, sanitized down to the toolbar's own tags. --}}
    <div class="body">{!! RichText::toSafeHtml($proposal->body) !!}</div>

    @if (filled($proposal->disclaimer))
        <div class="disclaimer">{{ $proposal->disclaimer }}</div>
    @endif


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

    @if ($team->isNotEmpty())
        <div class="team">
            <div class="section-title">{{ $proposal->team_heading ?: App\Models\Proposal::DEFAULT_TEAM_HEADING }}</div>
            @foreach ($team as $member)
                <table class="member">
                    <tr>
                        <td class="member-photo">
                            @if ($member->bio_photo_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk(config('filesystems.private_disk'))->path($member->bio_photo_path) }}" alt="">
                            @else
                                <div class="member-initials">{{ collect(explode(' ', $member->name))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') }}</div>
                            @endif
                        </td>
                        <td>
                            <div class="member-name">{{ $member->name }}</div>
                            @if ($member->job_title)
                                <div class="member-title">{{ $member->job_title }}</div>
                            @endif
                            @if (! RichText::isBlank($member->bio))
                                <div class="member-bio">{!! RichText::toSafeHtml($member->bio) !!}</div>
                            @endif
                        </td>
                    </tr>
                </table>
            @endforeach
        </div>
    @endif


    @if ($about)
        <div class="about">
            <div class="section-title">{{ $about['heading'] }}</div>
            <div class="about-body">{!! $about['body'] !!}</div>
        </div>
    @endif
</body>
</html>
