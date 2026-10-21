<?php

use App\Http\Controllers\Api\V1\MediaController;
use Illuminate\Support\Facades\Route;

Route::patch('/media/{id}', [MediaController::class, 'update']);
