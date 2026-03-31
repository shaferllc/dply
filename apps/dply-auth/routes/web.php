<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'index');

Route::view('/home', 'home')
    ->middleware(['auth'])
    ->name('home');
