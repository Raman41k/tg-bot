<?php

use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('api')->group(function () {
    Route::apiResource('tasks', TaskController::class);
});
