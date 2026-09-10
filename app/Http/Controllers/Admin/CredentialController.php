<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use Illuminate\Http\Request;

/**
 * Issuing, rotating and revoking API credentials.
 *
 * ROTATION AND REVOCATION ARE ONE CLICK ON PURPOSE (brief §9). A departing
 * bursar holding a live API key is a real risk, not a theoretical one, and a
 * control that takes a database console to exercise will not be exercised at
 * the moment it matters — which is the afternoon somebody leaves.
 *
 * The plaintext secret is shown EXACTLY ONCE, flashed to the next request and
 * never stored anywhere readable. It cannot be recovered afterwards, only
 * replaced. A secret the platform can redisplay on demand is a secret every
 * operator with console access can quietly copy.
 */
class CredentialController extends Controller
{
    public function store(Request $request, Institution $institution)
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:191'],
        ]);

        $issued = InstitutionCredential::issue($institution, $data['label'] ?? null, $request->user()->id);

        AuditLog::log('credential.issued', InstitutionCredential::class, $issued['credential']->id, null, [
            'institution_id' => $institution->id,
            'key_id' => $issued['credential']->key_id,
        ]);

        return redirect()
            ->route('admin.institutions.show', $institution)
            // Flashed, not stored. This is the only moment it exists in
            // readable form anywhere in the platform.
            ->with('secret', ['key_id' => $issued['credential']->key_id, 'secret' => $issued['secret']])
            ->with('success', __('Credential issued for :name.', ['name' => $institution->name]));
    }

    /**
     * Replace a credential, leaving the old one alive for a grace period.
     *
     * The old key is NOT killed instantly. These are bursary servers on
     * unreliable power: an institution whose client receives the new secret and
     * crashes before writing it to disk would be locked out until an operator
     * intervened. Immediate revocation is available separately, for when that is
     * what you actually mean.
     */
    public function rotate(Request $request, Institution $institution, InstitutionCredential $credential)
    {
        abort_unless($credential->institution_id === $institution->id, 404);

        if (! $credential->isActive()) {
            return back()->with('error', __('That credential is already revoked. Issue a new one instead.'));
        }

        $issued = $credential->rotate($request->user()->id, __('Rotated by :user', ['user' => $request->user()->name]));

        AuditLog::log('credential.rotated', InstitutionCredential::class, $issued['credential']->id, null, [
            'institution_id' => $institution->id,
            'from_key_id' => $credential->key_id,
            'to_key_id' => $issued['credential']->key_id,
            'initiated_by' => 'operator',
        ]);

        return redirect()
            ->route('admin.institutions.show', $institution)
            ->with('secret', ['key_id' => $issued['credential']->key_id, 'secret' => $issued['secret']])
            ->with('warning', __(
                'The previous key :key still works until the institution has deployed the new one. Revoke it once they confirm.',
                ['key' => $credential->key_id]
            ));
    }

    /**
     * Kill a credential now.
     *
     * The row is retained, never deleted, so the trail can still explain a
     * report that was signed with a key which no longer works.
     */
    public function revoke(Request $request, Institution $institution, InstitutionCredential $credential)
    {
        abort_unless($credential->institution_id === $institution->id, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:191'],
        ], [], ['reason' => __('reason')]);

        if (! $credential->isActive()) {
            return back()->with('error', __('That credential is already revoked.'));
        }

        $credential->revoke($data['reason'], $request->user()->id);

        $remaining = $institution->credentials()->active()->count();

        return redirect()
            ->route('admin.institutions.show', $institution)
            ->with($remaining === 0 ? 'warning' : 'success', $remaining === 0
                // Worth saying plainly: from the console this institution will
                // now look exactly like one that has gone quiet.
                ? __('Key revoked. :name now has no active credential and cannot report until you issue one.', ['name' => $institution->name])
                : __('Key revoked.'));
    }
}
