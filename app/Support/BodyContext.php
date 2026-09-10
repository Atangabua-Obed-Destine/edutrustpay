<?php

namespace App\Support;

use App\Models\Body;

/**
 * Resolves the body whose data the current request may see.
 *
 * Modelled on edutrustshsm's BranchContext — an all-static class over the
 * session and the authenticated user, with a per-request memo, and no container
 * binding. That shape is deliberate there and here: BodyScope runs on every
 * query of every body-owned model, and resolving the context has to be cheap
 * enough to happen thousands of times per page.
 *
 * THE ONE REAL DIFFERENCE FROM BranchContext: bodies nest.
 *
 * Branches are a flat list; bodies are a tree — the Archdiocese contains
 * deaneries, and a deanery might contain more. "Everything under this body" is
 * therefore not a single id but a subtree, and it is resolved through the
 * materialised `path` column with one indexed LIKE rather than a recursive walk.
 * A deanery bursar sees their deanery and everything beneath it; the centre sees
 * all of it; neither can see sideways.
 */
class BodyContext
{
    public const SESSION_KEY = 'active_body_id';

    /**
     * The body the operator is currently looking through the eyes of.
     *
     * Kept separate from SESSION_KEY on purpose. SESSION_KEY is a body user's
     * choice of which of THEIR bodies to look at, and it never widens what they
     * may see. This one NARROWS the operator down to somebody else's view, and
     * conflating the two would make "which body am I in" and "am I pretending"
     * the same question — which is exactly the question a banner has to answer
     * unambiguously.
     */
    public const IMPERSONATION_KEY = 'impersonated_body_id';

    /**
     * Consolidated mode: this body and every body nested beneath it. This is the
     * normal way a bursary head at the centre looks at the group, so it is not
     * an exotic admin feature — it is the default view for anyone whose body has
     * children.
     */
    public const SUBTREE = 'subtree';

    /**
     * Per-request memo.
     *
     * BranchContext acquired this the hard way: the scope ran on ~70 models and
     * called accessibleIds() two or three times each, every one a live lookup,
     * so a single page could issue hundreds of queries just deciding where it
     * was. Ours would be worse, because resolving a subtree touches the tree.
     *
     * @var array<int, int>|null
     */
    private static ?array $accessibleIds = null;

    private static ?int $currentId = null;

    /**
     * Whether there is an authenticated user to scope by.
     *
     * False in console commands, queue workers, migrations and seeders, which is
     * what makes the global scope a no-op there — the ingest queue must be able
     * to write a report for any institution without a session.
     */
    public static function isActive(): bool
    {
        return auth()->check();
    }

    /**
     * Is the request ACTING as the platform operator right now?
     *
     * False while impersonating, and that is the whole mechanism: this one
     * method is what widens accessible ids to every body, what unlocks the
     * operator navigation, and what lets `User::scopeAccessible` skip its
     * filter. Turning it off in one place turns the operator's reach off
     * everywhere at once, rather than leaving each of those three sites to
     * remember the impersonation case separately.
     *
     * Use isOperatorAccount() for the question "is this person the operator",
     * which stays true throughout.
     */
    public static function isSuperAdmin(): bool
    {
        return self::isOperatorAccount() && ! self::isImpersonating();
    }

    /**
     * Does this account hold the operator role, regardless of what it is
     * currently looking at?
     */
    public static function isOperatorAccount(): bool
    {
        return auth()->check() && auth()->user()->role === 'super_admin';
    }

    /**
     * The body being impersonated, or null.
     *
     * Guarded on the role rather than on the session key alone: a stale key
     * surviving into a body user's session must be inert, never a lever.
     */
    public static function impersonatedId(): ?int
    {
        if (! auth()->check() || auth()->user()->role !== 'super_admin') {
            return null;
        }

        $id = session(self::IMPERSONATION_KEY);

        return $id ? (int) $id : null;
    }

    public static function isImpersonating(): bool
    {
        return self::impersonatedId() !== null;
    }

    public static function impersonatedBody(): ?Body
    {
        $id = self::impersonatedId();

        return $id ? Body::query()->find($id) : null;
    }

    /**
     * Look at the platform as this body's own people see it.
     *
     * Read-only, enforced by middleware rather than by care: see
     * App\Http\Middleware\HandleImpersonation. An operator who could answer a
     * finding from behind this banner would leave a resolution in the record
     * that the body never made.
     */
    public static function impersonate(Body $body): void
    {
        session([self::IMPERSONATION_KEY => $body->id]);

        // The body switcher's own choice belongs to the previous view and would
        // otherwise point at a body outside the one now being impersonated.
        session()->forget(self::SESSION_KEY);

        self::flush();
    }

    public static function stopImpersonating(): void
    {
        session()->forget(self::IMPERSONATION_KEY);
        session()->forget(self::SESSION_KEY);

        self::flush();
    }

    /**
     * Every body id the current user may read.
     *
     * @return array<int, int>
     */
    public static function accessibleIds(): array
    {
        return self::$accessibleIds ??= self::resolveAccessibleIds();
    }

    /** @return array<int, int> */
    private static function resolveAccessibleIds(): array
    {
        if (! auth()->check()) {
            return [];
        }

        $user = auth()->user();

        if (($user->role ?? null) === 'super_admin') {
            // While impersonating, the operator's reach is the impersonated
            // body's subtree and nothing else — so the console cannot show a
            // consolidated total, a finding or an institution that this body's
            // own people would never see. A view that quietly included one more
            // institution than the real user gets would be worse than no view.
            if ($impersonated = self::impersonatedId()) {
                return self::subtreeIds($impersonated);
            }

            // Otherwise the operator sees every body. Note this is a WIDENING of
            // accessible ids, not a bypass of the scope: queries stay filtered,
            // so a bug in the scope cannot silently expose data it was never
            // meant to.
            return Body::query()->pluck('id')->map('intval')->all();
        }

        if (! $user->body_id) {
            return [];
        }

        return self::subtreeIds((int) $user->body_id);
    }

    /**
     * A body and every body nested beneath it, in one indexed query.
     *
     * @return array<int, int>
     */
    public static function subtreeIds(int $bodyId): array
    {
        $body = Body::query()->find($bodyId);

        if (! $body) {
            return [];
        }

        return Body::query()
            ->where(function ($q) use ($body, $bodyId) {
                $q->where('id', $bodyId)
                    ->orWhere('path', 'like', $body->path.'%');
            })
            ->pluck('id')
            ->map('intval')
            ->all();
    }

    /**
     * Is the user looking at their whole subtree rather than one body?
     *
     * Only meaningful when there is actually more than one body to consolidate.
     */
    public static function isSubtree(): bool
    {
        return session(self::SESSION_KEY) === self::SUBTREE && count(self::accessibleIds()) > 1;
    }

    /**
     * The single active body id. Returns 0 for "no body context yet", which
     * callers must treat as such rather than as body 0.
     */
    public static function current(): int
    {
        return self::$currentId ??= self::resolveCurrent();
    }

    private static function resolveCurrent(): int
    {
        $accessible = self::accessibleIds();

        if ($accessible === []) {
            return 0;
        }

        // Impersonation stands in for "your own body", which is what the
        // person being impersonated would land on. Without this the operator
        // would land on whichever subtree row the database returned first —
        // possibly a deanery rather than the archdiocese they clicked.
        if ($impersonated = self::impersonatedId()) {
            return $impersonated;
        }

        $session = session(self::SESSION_KEY);

        if ($session && $session !== self::SUBTREE && in_array((int) $session, $accessible, true)) {
            return (int) $session;
        }

        // A body user defaults to their own body; the operator to the first.
        $own = auth()->user()->body_id ?? null;

        if ($own && in_array((int) $own, $accessible, true)) {
            return (int) $own;
        }

        return (int) $accessible[0];
    }

    public static function currentBody(): ?Body
    {
        $id = self::current();

        return $id ? Body::query()->find($id) : null;
    }

    /**
     * The body whose identity the page should WEAR — crest, name, accent.
     *
     * Not the same question as currentBody(), and conflating them was a real
     * bug: the operator belongs to no body, so current() falls through to the
     * first accessible one, and the operator console silently dressed itself in
     * whichever body happened to sort first. Create a body, give it a colour,
     * and the operator's own chrome changed.
     *
     * The operator is outside every body and wears the platform's identity.
     * The one exception is impersonation, where wearing the body's colours is
     * the entire point — and isSuperAdmin() is already false there, so this
     * needs no special case.
     */
    public static function chromeBody(): ?Body
    {
        return self::isSuperAdmin() ? null : self::currentBody();
    }

    /**
     * The accent colour in force for this request.
     */
    public static function accent(): string
    {
        return self::chromeBody()?->accent() ?? config('platform.accent');
    }

    /**
     * The ids a query should actually be filtered to right now.
     *
     * @return array<int, int>
     */
    public static function scopeIds(): array
    {
        if (self::isSubtree() || self::isSuperAdmin()) {
            return self::accessibleIds();
        }

        $current = self::current();

        // A body user always sees their own body's subtree: a deanery head who
        // could see the deanery but not the parishes under it would be looking
        // at a consolidated total that excludes most of what they are
        // responsible for, which is worse than no total.
        return $current ? self::subtreeIds($current) : [];
    }

    /**
     * @param  int|string  $bodyId  a body id, or self::SUBTREE
     */
    public static function set($bodyId): void
    {
        session([self::SESSION_KEY => $bodyId === self::SUBTREE ? self::SUBTREE : (int) $bodyId]);

        self::flush();
    }

    /**
     * Clear the per-request memo.
     *
     * Called by ResolveBodyContext at the start of every web request. Without it
     * the memo leaks across requests under Octane, and across tests and queued
     * jobs in the same process — which would show one body's figures to another
     * body's user, the single failure this system cannot have.
     */
    public static function flush(): void
    {
        self::$accessibleIds = null;
        self::$currentId = null;
    }

    /**
     * Bodies the user may switch between, for the header switcher.
     *
     * @return \Illuminate\Support\Collection<int, Body>
     */
    public static function availableBodies()
    {
        return Body::query()
            ->whereIn('id', self::accessibleIds())
            ->orderBy('path')
            ->get();
    }

    public static function showSwitcher(): bool
    {
        return count(self::accessibleIds()) > 1;
    }
}
