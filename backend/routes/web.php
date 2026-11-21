<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'alchemy_of_dreams',
        'status' => 'ok',
    ]);
});
