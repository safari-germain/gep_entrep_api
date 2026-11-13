<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () { return ['app' => 'GEP Entreprendre API', 'version' => '1.0']; });
