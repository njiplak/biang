{{-- The plain-text twin of html/message.blade.php. See the comment there. --}}
@php
    $legal = \App\Models\Page::legalLinks();
@endphp
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ config('app.name') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ config('app.name') }}. @lang('All rights reserved.')
            @if ($legal['terms'])

            @lang('Terms of Service'): {{ $legal['terms'] }}
            @endif
            @if ($legal['privacy'])

            @lang('Privacy Policy'): {{ $legal['privacy'] }}
            @endif
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
