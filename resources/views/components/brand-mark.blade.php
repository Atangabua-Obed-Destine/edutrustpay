@props(['class' => 'h-10 w-10'])

{{--
    The EdutrustPay mark: a shield, for figures that arrive signed, with the
    check that says they were looked at. Navy and gold, the platform's own
    colours, never a body's: it is shown before anyone has signed in.
--}}
@php $id = 'bm'.substr(md5(uniqid('', true)), 0, 6); @endphp
<svg viewBox="0 0 48 48" class="{{ $class }}" role="img" aria-label="{{ platform_name() }}" {{ $attributes->except('class') }}>
    <defs>
        <linearGradient id="{{ $id }}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#1f3a5f"/>
            <stop offset="1" stop-color="#0b1a2e"/>
        </linearGradient>
    </defs>
    <rect width="48" height="48" rx="12" fill="url(#{{ $id }})"/>
    <rect x="0.75" y="0.75" width="46.5" height="46.5" rx="11.25" fill="none" stroke="#ffffff" stroke-opacity="0.12"/>
    <path d="M24 9.5l12 4.4v9.1c0 7.5-5 13.5-12 16.2-7-2.7-12-8.7-12-16.2v-9.1L24 9.5z"
          fill="#e8b54a" fill-opacity="0.12" stroke="#e8b54a" stroke-width="2.2" stroke-linejoin="round"/>
    <path d="M18.6 24.4l3.9 3.9 7.6-8" fill="none" stroke="#ffffff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
