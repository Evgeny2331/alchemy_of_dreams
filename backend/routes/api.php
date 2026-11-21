<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Alchemy\Controllers\AlchemyController;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\City\Controllers\CityController;
use App\Modules\Economy\Controllers\EconomyController;
use App\Modules\Expedition\Controllers\ExpeditionController;
use App\Modules\Residents\Controllers\ResidentController;
use App\Modules\WorldPhase\Controllers\WorldPhaseController;

Route::prefix('v1')->group(function () {
    Route::post('/auth/telegram', [AuthController::class, 'telegram']);

    Route::middleware('player.session')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/game/sync', [CityController::class, 'state']);
        Route::get('/city/state', [CityController::class, 'state']);
        Route::get('/city/{city}/residents', [ResidentController::class, 'listByCity']);
        Route::post('/city/islands/{island}/unlock', [CityController::class, 'unlockIsland']);

        Route::get('/buildings/catalog', [CityController::class, 'catalog']);
        Route::post('/buildings/preview', [CityController::class, 'preview']);
        Route::post('/buildings', [CityController::class, 'build']);
        Route::patch('/buildings/{building}/position', [CityController::class, 'move']);
        Route::post('/buildings/{building}/upgrade', [CityController::class, 'upgrade']);
        Route::delete('/buildings/{building}', [CityController::class, 'demolish']);

        Route::post('/city/buildings', [CityController::class, 'build']);
        Route::post('/city/buildings/{building}/upgrade', [CityController::class, 'upgrade']);
        Route::post('/city/buildings/{building}/demolish', [CityController::class, 'demolish']);

        Route::post('/economy/sync-fog', [EconomyController::class, 'syncFog']);

        Route::post('/residents/{resident}/assign', [ResidentController::class, 'assign']);
        Route::post('/residents/{resident}/unassign', [ResidentController::class, 'unassign']);
        Route::post('/residents/{resident}/requests', [ResidentController::class, 'generateRequest']);
        Route::post('/resident-requests/{residentRequest}/complete', [ResidentController::class, 'completeRequest']);
        Route::post('/resident-requests/{residentRequest}/decline', [ResidentController::class, 'declineRequest']);

        Route::get('/expeditions', [ExpeditionController::class, 'index']);
        Route::post('/expeditions', [ExpeditionController::class, 'start']);
        Route::post('/expeditions/start', [ExpeditionController::class, 'start']);
        Route::post('/expeditions/{expedition}/complete', [ExpeditionController::class, 'complete']);
        Route::post('/expeditions/{expedition}/claim', [ExpeditionController::class, 'claim']);

        Route::get('/alchemy/archive', [AlchemyController::class, 'archive']);
        Route::post('/alchemy/experiment', [AlchemyController::class, 'transmute']);
        Route::post('/alchemy/transmute', [AlchemyController::class, 'transmute']);

        Route::get('/world/phase', [WorldPhaseController::class, 'current']);
    });
});
