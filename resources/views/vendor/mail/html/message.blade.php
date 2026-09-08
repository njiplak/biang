{{--
    Overrides the framework's mail message wrapper for one reason: spec section
    11 says the app links to terms and privacy "from signup and from email
    footers". Signup is handled on the register page; this is the other half,
    and doing it here covers every notification at once rather than asking nine
    notification classes to remember.

    Only this file and its text twin are published. Every other mail component
    is untouched, so they keep tracking the framework.
--}}
@php
    $legal = \App\Models\Page::legalLinks();

    // Built here rather than chained inline: a page that is still a draft
    // contributes nothing, so the footer never links to a 404.
    $legalLinks = collect([
        $legal['terms'] ? '['.__('Terms of Service').']('.$legal['terms'].')' : null,
        $legal['privacy'] ? '['.__('Privacy Policy').']('.$legal['privacy'].')' : null,
    ])->filter()->implode(' · ');
@endphp
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

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

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
@if ($legalLinks !== '')

{!! $legalLinks !!}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
