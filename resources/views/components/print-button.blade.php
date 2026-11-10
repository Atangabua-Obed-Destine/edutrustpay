{{-- Prints the page as it stands: the menu and buttons drop away, and a line at the foot says who printed it and when. --}}
<button type="button" onclick="window.print()"
        class="no-print inline-flex items-center gap-1.5 rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 9V3h10v6M7 18H5a2 2 0 01-2-2v-5a2 2 0 012-2h14a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="7" y="14" width="10" height="7" rx="1"/></svg>
    {{ __('Print') }}
</button>
