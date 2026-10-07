<?php

use Illuminate\Support\Facades\Route;

// Every non-API path is handled by the React router inside the SPA shell.
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|up).*$')->name('spa');
