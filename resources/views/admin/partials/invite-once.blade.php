@php $invite = session('invite'); @endphp

{{--
    The invitation link, shown once.

    Not a password: it is single-use, expires, and is destroyed the moment it is
    redeemed. Shown on screen rather than mailed because mail is often not
    configured on a fresh install, and an invitation that silently fails to send
    is worse than one you have to copy by hand.
--}}
<div class="mb-6 overflow-hidden rounded border-2 border-sky-400 bg-sky-50">
    <div class="border-b border-sky-300 px-4 py-2">
        <p class="text-sm font-bold text-sky-900">
            {{ __('Invitation link for :name', ['name' => $invite['name']]) }}
        </p>
    </div>

    <div class="space-y-3 p-4">
        <code id="etp-invite" class="block break-all rounded bg-surface px-3 py-2 font-mono text-xs">{{ $invite['url'] }}</code>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" data-copy="#etp-invite"
                    class="rounded bg-sky-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-sky-700">
                {{ __('Copy link') }}
            </button>
            <span class="text-xs text-sky-900">
                {{ __('Single use, expires :date. They set their own password — no password is ever generated or sent.', ['date' => $invite['expires']]) }}
            </span>
        </div>
    </div>
</div>
