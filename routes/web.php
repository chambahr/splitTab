<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'SplitTrip API', 'version' => 'v1', 'status' => 'ok']));
