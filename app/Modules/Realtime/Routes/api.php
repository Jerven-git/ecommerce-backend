<?php

use App\Modules\Realtime\Http\Controllers\VersionController;
use Illuminate\Support\Facades\Route;

Route::get('/version', VersionController::class);
