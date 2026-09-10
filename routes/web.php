<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\BodyController;
use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\InstitutionController as AdminInstitutionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Body\CapabilityMatrixController;
use App\Http\Controllers\Body\ConsolidationController;
use App\Http\Controllers\Body\DashboardController as BodyDashboard;
use App\Http\Controllers\Body\FindingController;
use App\Http\Controllers\Body\InstitutionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Console routes
|--------------------------------------------------------------------------
|
| Two surfaces with different audiences (brief §1):
|   /admin — the EdutrustPay operator
|   /body  — the body's own people
|
| Coarse `role:` gating at the group level, fine-grained `permission:` on the
| actions, which is edutrustshsm's defence-in-depth arrangement.
|
*/

Route::get('/', fn () => redirect()->route(auth()->check() ? 'body.dashboard' : 'login'));

// ═══════════════════════════════════════════════════════════════════
// Authentication
// ═══════════════════════════════════════════════════════════════════

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
 * Leaving "view as body" sits OUTSIDE the /admin group on purpose: that whole
 * group is redirected away while impersonating, so a way out placed inside it
 * would redirect to the console it is trying to leave.
 */
Route::post('impersonate/stop', [ImpersonationController::class, 'destroy'])
    ->middleware(['auth', 'role:super_admin'])->name('impersonate.stop');

// Accepting an invitation. Public by necessity — the invitee has no account yet
// — but the token is single-use, expires, and is destroyed on redemption.
Route::get('invite/{token}', [InvitationController::class, 'show'])->name('invite.show');
Route::post('invite/{token}', [InvitationController::class, 'store'])
    ->middleware('throttle:6,1')->name('invite.store');

// ═══════════════════════════════════════════════════════════════════
// Body console
// ═══════════════════════════════════════════════════════════════════

Route::middleware('auth')->prefix('body')->name('body.')->group(function () {

    Route::get('/', BodyDashboard::class)->name('dashboard');

    Route::middleware('permission:institution.view')->group(function () {
        Route::get('institutions', [InstitutionController::class, 'index'])->name('institutions.index');
        Route::get('institutions/{institution}', [InstitutionController::class, 'show'])->name('institutions.show');

        /*
         * Deeper than the overview, and still above the transaction line.
         *
         * `return` is one month in full — every block the contract carries,
         * including the ones this institution cannot report. `year` is the
         * same figures accumulated into the five-column statement the
         * institution's own bursar already reads.
         */
        Route::get('institutions/{institution}/year', [InstitutionController::class, 'annual'])
            ->name('institutions.annual');
        Route::get('institutions/{institution}/return/{period}', [InstitutionController::class, 'monthly'])
            ->where('period', '[0-9]{4}-[0-9]{2}')
            ->name('institutions.monthly');
    });

    Route::get('capabilities', CapabilityMatrixController::class)
        ->middleware('permission:capability.view')->name('capabilities');

    Route::middleware('permission:finding.view')->group(function () {
        Route::get('findings', [FindingController::class, 'index'])->name('findings.index');
        Route::get('findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
    });

    // Answering a finding is a separate permission from seeing one: an auditor
    // who can close the findings they are auditing is not an auditor.
    Route::post('findings/{finding}/resolve', [FindingController::class, 'resolve'])
        ->middleware('permission:finding.resolve')->name('findings.resolve');

    Route::middleware('permission:consolidation.view')->group(function () {
        Route::get('consolidation', [ConsolidationController::class, 'show'])->name('consolidation');
        Route::get('consolidation/export', [ConsolidationController::class, 'export'])
            ->middleware('permission:consolidation.export')->name('consolidation.export');
    });
});

// ═══════════════════════════════════════════════════════════════════
// Superadmin
// ═══════════════════════════════════════════════════════════════════

Route::middleware(['auth', 'role:super_admin'])->prefix('admin')->name('admin.')->group(function () {

    Route::get('/', AdminDashboard::class)->name('dashboard');

    // Bodies nest, so `parent` is accepted as a query parameter on create.
    Route::resource('bodies', BodyController::class)->parameters(['bodies' => 'body']);

    // Look at the platform as this body's own people see it. Read-only; see
    // HandleImpersonation for why that is enforced rather than trusted.
    Route::post('bodies/{body}/impersonate', [ImpersonationController::class, 'store'])
        ->name('bodies.impersonate');

    Route::resource('institutions', AdminInstitutionController::class)->except(['destroy']);

    /*
     * Capabilities and the onboarding checklist hang off an institution rather
     * than being resources of their own: neither means anything apart from the
     * institution it describes.
     */
    Route::put('institutions/{institution}/capabilities', [AdminInstitutionController::class, 'capabilities'])
        ->name('institutions.capabilities');
    Route::post('institutions/{institution}/onboarding', [AdminInstitutionController::class, 'onboarding'])
        ->name('institutions.onboarding');

    // Credential lifecycle. Rotation and revocation are one click by design —
    // a departing bursar with a live key is a real risk (brief §9).
    Route::post('institutions/{institution}/credentials', [CredentialController::class, 'store'])
        ->name('credentials.store');
    Route::post('institutions/{institution}/credentials/{credential}/rotate', [CredentialController::class, 'rotate'])
        ->name('credentials.rotate');
    Route::post('institutions/{institution}/credentials/{credential}/revoke', [CredentialController::class, 'revoke'])
        ->name('credentials.revoke');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::post('users/{user}/reinvite', [UserController::class, 'reinvite'])->name('users.reinvite');
    Route::post('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
});
