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
use App\Http\Controllers\Body\BudgetSheetController as BodyBudgetSheetController;
use App\Http\Controllers\Body\ComplianceController;
use App\Http\Controllers\Body\DaybookController as BodyDaybookController;
use App\Http\Controllers\Body\RegulationController;
use App\Http\Controllers\Body\ConsolidationController;
use App\Http\Controllers\Body\DashboardController as BodyDashboard;
use App\Http\Controllers\Body\GuideController;
use App\Http\Controllers\Body\FindingController;
use App\Http\Controllers\Body\InstitutionController;
use App\Http\Controllers\Body\RoleController;
use App\Http\Middleware\EnsureBodyView;
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

// First run. Opened from the link `php artisan edutrustpay:install` prints;
// once an account exists it only ever redirects to the login page.
Route::get('install/{token?}', [\App\Http\Controllers\InstallController::class, 'show'])->name('install.show');
Route::post('install/{token}', [\App\Http\Controllers\InstallController::class, 'store'])
    ->middleware('throttle:6,1')->name('install.store');

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect()->route(auth()->user()->isSuperAdmin() ? 'admin.dashboard' : 'body.dashboard');
});

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

Route::middleware(['auth', EnsureBodyView::class])->prefix('body')->name('body.')->group(function () {

    Route::get('/', BodyDashboard::class)->name('dashboard');

    // Open to everyone in the body: it explains the console, and shows each
    // person only the pages they can open.
    Route::get('guide', [GuideController::class, 'show'])->name('guide');
    Route::post('guide/hide', [GuideController::class, 'hide'])->name('guide.hide');
    Route::post('guide/show', [GuideController::class, 'restore'])->name('guide.restore');

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
        Route::get('institutions/{institution}/budget', [BodyBudgetSheetController::class, 'show'])
            ->name('institutions.budget');
        Route::get('institutions/{institution}/daybook', [BodyDaybookController::class, 'show'])
            ->middleware('permission:daybook.view')
            ->name('institutions.daybook');
        // Names people (number, name, department, post), so it has its own permission.
        Route::get('institutions/{institution}/staff', [\App\Http\Controllers\Body\StaffController::class, 'show'])
            ->middleware('permission:staff.view')
            ->name('institutions.staff');
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

    /*
     * The rules a body sets, and how its schools measure up. The body console's
     * reason to exist for an Education Secretary.
     */
    Route::get('daybook/compare', [BodyDaybookController::class, 'compare'])
        ->middleware('permission:daybook.compare')
        ->name('daybook.compare');

    Route::prefix('compliance')->name('compliance.')->group(function () {
        Route::get('/', [ComplianceController::class, 'index'])->middleware('permission:compliance.view')->name('index');

        Route::get('rules', [RegulationController::class, 'index'])->middleware('permission:compliance.view')->name('regulations.index');
        Route::get('rules/create', [RegulationController::class, 'create'])->middleware('permission:compliance.manage')->name('regulations.create');
        Route::post('rules', [RegulationController::class, 'store'])->middleware('permission:compliance.manage')->name('regulations.store');
        Route::get('rules/{regulation}/edit', [RegulationController::class, 'edit'])->middleware('permission:compliance.manage')->name('regulations.edit');
        Route::put('rules/{regulation}', [RegulationController::class, 'update'])->middleware('permission:compliance.manage')->name('regulations.update');
        Route::post('rules/{regulation}/toggle', [RegulationController::class, 'toggle'])->middleware('permission:compliance.manage')->name('regulations.toggle');

        Route::get('{regulation}/{institution}', [ComplianceController::class, 'show'])->whereNumber('regulation')->middleware('permission:compliance.view')->name('show');
        Route::post('{regulation}/{institution}/mappings', [ComplianceController::class, 'saveMappings'])->whereNumber('regulation')->middleware('permission:compliance.manage')->name('mappings');
        Route::post('{regulation}/{institution}/waiver', [ComplianceController::class, 'waive'])->whereNumber('regulation')->middleware('permission:compliance.waive')->name('waiver');
        Route::post('{regulation}/{institution}/waiver/{waiver}/revoke', [ComplianceController::class, 'revokeWaiver'])->whereNumber('regulation')->middleware('permission:compliance.waive')->name('waiver.revoke');
        Route::post('{regulation}/{institution}/attest', [ComplianceController::class, 'attest'])->whereNumber('regulation')->middleware('permission:compliance.follow_up')->name('attest');
        Route::post('{regulation}/{institution}/follow-up', [ComplianceController::class, 'openCase'])->whereNumber('regulation')->middleware('permission:compliance.follow_up')->name('cases.open');
        Route::post('{regulation}/{institution}/follow-up/{case}/close', [ComplianceController::class, 'closeCase'])->whereNumber('regulation')->middleware('permission:compliance.follow_up')->name('cases.close');
    });

    /*
     * The body runs its own schools and staff. Same screens as the operator's,
     * limited to this body and those nested inside it. Permission checked per
     * action, so a role can be given exactly what it needs.
     */
    Route::prefix('manage')->name('manage.')->group(function () {
        Route::get('institutions/create', [AdminInstitutionController::class, 'create'])
            ->middleware('permission:institution.create')->name('institutions.create');
        Route::post('institutions', [AdminInstitutionController::class, 'store'])
            ->middleware('permission:institution.create')->name('institutions.store');
        Route::get('institutions/{institution}', [AdminInstitutionController::class, 'show'])
            ->middleware('permission:institution.update|capability.declare|credential.view')->name('institutions.show');
        Route::get('institutions/{institution}/edit', [AdminInstitutionController::class, 'edit'])
            ->middleware('permission:institution.update')->name('institutions.edit');
        Route::put('institutions/{institution}', [AdminInstitutionController::class, 'update'])
            ->middleware('permission:institution.update')->name('institutions.update');
        Route::put('institutions/{institution}/capabilities', [AdminInstitutionController::class, 'capabilities'])
            ->middleware('permission:capability.declare')->name('institutions.capabilities');
        Route::post('institutions/{institution}/onboarding', [AdminInstitutionController::class, 'onboarding'])
            ->middleware('permission:institution.update')->name('institutions.onboarding');

        Route::post('institutions/{institution}/credentials', [CredentialController::class, 'store'])
            ->middleware('permission:credential.issue')->name('credentials.store');
        Route::post('institutions/{institution}/credentials/{credential}/rotate', [CredentialController::class, 'rotate'])
            ->middleware('permission:credential.rotate')->name('credentials.rotate');
        Route::post('institutions/{institution}/credentials/{credential}/revoke', [CredentialController::class, 'revoke'])
            ->middleware('permission:credential.revoke')->name('credentials.revoke');

        Route::get('people', [UserController::class, 'index'])->middleware('permission:user.view')->name('users.index');
        Route::get('people/create', [UserController::class, 'create'])->middleware('permission:user.invite')->name('users.create');
        Route::post('people', [UserController::class, 'store'])->middleware('permission:user.invite')->name('users.store');
        Route::get('people/{user}/edit', [UserController::class, 'edit'])->middleware('permission:user.update')->name('users.edit');
        Route::put('people/{user}', [UserController::class, 'update'])->middleware('permission:user.update')->name('users.update');
        Route::post('people/{user}/reinvite', [UserController::class, 'reinvite'])->middleware('permission:user.invite')->name('users.reinvite');
        Route::post('people/{user}/toggle', [UserController::class, 'toggle'])->middleware('permission:user.deactivate')->name('users.toggle');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:role.view')->name('roles.index');
        Route::get('roles/create', [RoleController::class, 'create'])->middleware('permission:role.manage')->name('roles.create');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:role.manage')->name('roles.store');
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->middleware('permission:role.manage')->name('roles.edit');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:role.manage')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:role.manage')->name('roles.destroy');
    });

    Route::middleware('permission:consolidation.view')->group(function () {
        Route::get('consolidation', [ConsolidationController::class, 'show'])->name('consolidation');
        // Every school's budget on its totals, side by side. Its lines stay on its own budget page.
        Route::get('budgets', [\App\Http\Controllers\Body\BudgetCompareController::class, 'index'])->name('budgets.compare');
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
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('users/{user}/reinvite', [UserController::class, 'reinvite'])->name('users.reinvite');
    Route::post('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
});
