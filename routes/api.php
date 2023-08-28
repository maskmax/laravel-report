<?php

use Illuminate\Support\Facades\Route;
use RK\Report\app\Http\Controllers\Api\ReportController;

Route::prefix('api')->group(function () {
    Route::apiResource('reports', ReportController::class);
});
