<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Body;
use App\Support\BodyContext;

/**
 * "Show me what they are seeing."
 *
 * The operator supports bursary heads they cannot sit next to, over a phone, in
 * a country where the connection may not survive a screenshot. Reproducing a
 * complaint about the console by reasoning about scopes and capability
 * declarations is slow and gets it wrong; looking at the same page they are
 * looking at does not.
 *
 * Both acts are audited. Impersonation is the one thing on this platform that
 * makes one person's session look like another's, so a record that it happened
 * — who, which body, when — is not optional. It is written OUTSIDE the
 * impersonated context, against the operator, because the operator is who did
 * it.
 */
class ImpersonationController extends Controller
{
    public function store(Body $body)
    {
        BodyContext::impersonate($body);

        // Logged AFTER the switch, so AuditLog stamps the row with the body
        // being viewed. That puts the record in the trail of the body it
        // concerns, where its own people can see that the operator looked —
        // which is the point of recording it at all.
        AuditLog::log('body.impersonation_started', Body::class, $body->id, null, [
            'body' => $body->name,
        ], $body->id);

        return redirect()->route('body.dashboard')->with('warning', __(
            'You are now viewing as :body. This view is read-only.',
            ['body' => $body->name]
        ));
    }

    public function destroy()
    {
        $body = BodyContext::impersonatedBody();

        if ($body) {
            AuditLog::log('body.impersonation_ended', Body::class, $body->id, null, [
                'body' => $body->name,
            ], $body->id);
        }

        BodyContext::stopImpersonating();

        return redirect()->route($body ? 'admin.bodies.show' : 'admin.dashboard', $body ?: [])
            ->with('success', __('Back to the operator console.'));
    }
}
