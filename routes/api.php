<?php

use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\CredentialController;
use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\Api\DaybookController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Middleware\VerifyInstitutionSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ingest API (brief §8)
|--------------------------------------------------------------------------
|
| Machines only. Institutions push signed period reports here; EdutrustPay
| never reaches back into an institution ("push, never pull", §4.4).
|
| Everything except /contract is authenticated by API key id + HMAC signature
| over the raw body — never by a bearer token alone.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    /*
     * Unauthenticated on purpose: a client that cannot sign correctly is exactly
     * the one that most needs to read this, and it discloses nothing that is not
     * already in the shared contract package.
     */
    Route::get('contract', [ContractController::class, 'show'])->name('contract');

    Route::middleware([VerifyInstitutionSignature::class, 'throttle:ingest'])->group(function () {

        Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
        Route::post('daybook', [DaybookController::class, 'store'])->name('daybook.store');

        // Liveness when there is nothing to report. Without this, a quiet month
        // and a stopped server are indistinguishable.
        Route::post('heartbeat', [HeartbeatController::class, 'store'])->name('heartbeat');

        Route::post('credentials/rotate', [CredentialController::class, 'rotate'])->name('credentials.rotate');
    });
});
