{{-- The notification frame: the card with the studio logo (in the layout),
     the body, and any small note under it -- no header or footer, as in the
     proposal and invoice emails. --}}
<x-mail::layout>
{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset
</x-mail::layout>
