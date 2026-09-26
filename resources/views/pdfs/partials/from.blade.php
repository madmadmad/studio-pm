{{-- The studio's "From" block, shared by pdfs.invoice and pdfs.proposal
     (the web pages use Components/DocumentFrom.jsx). --}}
<div class="label">From</div>
<div class="party-name">{{ $studio->name }}</div>
@if ($studio->address)
    <div class="muted">{!! nl2br(e($studio->address)) !!}</div>
@endif
@if ($studio->email)<div class="muted">{{ $studio->email }}</div>@endif
@if ($studio->phone)<div class="muted">{{ $studio->phone }}</div>@endif
