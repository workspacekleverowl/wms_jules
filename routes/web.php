<?php


use Illuminate\Support\Facades\Route;
Route::get('/clear-cache', function () {
    Artisan::call('cache:clear');
    Artisan::call('route:clear');
    Artisan::call('config:clear');
    Artisan::call('view:clear');
 
    return "Cache cleared successfully";
 });
 