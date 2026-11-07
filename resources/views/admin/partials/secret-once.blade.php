@php $secret = session('secret'); @endphp

{{--
    The one moment this secret exists in readable form anywhere in the platform.

    Styled to be impossible to scroll past, because it genuinely cannot be
    recovered: there is no "show secret" button anywhere, by design. An operator
    who closes this page without copying it must issue a new credential.
--}}
<div class="mb-6 overflow-hidden rounded border-2 border-amber-400 bg-amber-50">
    <div class="border-b border-amber-300 px-4 py-2">
        <p class="text-sm font-bold text-amber-900">
            {{ __('Copy this secret now — it cannot be shown again') }}
        </p>
    </div>

    <div class="space-y-3 p-4">
        <p class="text-sm text-amber-900">{{ __('The school enters these four values on its EdutrustPay Reporting settings page, then clicks Test connection.') }}</p>

        <div>
            <p class="text-xs uppercase tracking-wide text-amber-800">{{ __('Console address') }}</p>
            <code class="mt-0.5 block break-all rounded bg-surface px-3 py-2 font-mono text-sm">{{ rtrim(config('app.url'), '/') }}</code>
        </div>

        <div>
            <p class="text-xs uppercase tracking-wide text-amber-800">{{ __('Institution reference') }}</p>
            <code class="mt-0.5 block break-all rounded bg-surface px-3 py-2 font-mono text-sm">{{ $secret['ref'] ?? '' }}</code>
        </div>

        <div>
            <p class="text-xs uppercase tracking-wide text-amber-800">{{ __('Key id') }}</p>
            <code class="mt-0.5 block break-all rounded bg-surface px-3 py-2 font-mono text-sm">{{ $secret['key_id'] }}</code>
        </div>

        <div>
            <p class="text-xs uppercase tracking-wide text-amber-800">{{ __('Secret') }}</p>
            <code id="etp-secret" class="mt-0.5 block break-all rounded bg-surface px-3 py-2 font-mono text-sm">{{ $secret['secret'] }}</code>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" data-copy="#etp-secret"
                    class="rounded bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-700">
                {{ __('Copy secret') }}
            </button>
            <span class="text-xs text-amber-900">
                {{ __('Give it to the institution over a channel you trust. It is not recoverable from this console.') }}
            </span>
        </div>
    </div>
</div>
