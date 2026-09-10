@props(['href', 'active' => false, 'badge' => null])

<a href="{{ $href }}"
   @class([
       'flex items-center justify-between rounded px-2 py-1.5 transition',
       'bg-white/15 font-medium text-white' => $active,
       'text-white/70 hover:bg-white/10 hover:text-white' => ! $active,
   ])>
    <span>{{ $slot }}</span>

    @if ($badge)
        {{-- Findings waiting on someone are shown in the navigation itself:
             the landing page is ordered by what needs attention, and a count you
             have to navigate to in order to discover is not attention. --}}
        <span class="ml-2 rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $badge }}</span>
    @endif
</a>
