<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Traitement quotidien automatique du Compte Ordinaire ──────────────────
// Exécute chaque jour à 02h00 :
//  - Intérêts débiteurs sur les comptes en découvert
//  - Prélèvements automatiques des échéances de crédit échus
//  - Prélèvement mensuel de la caution solidaire (aux dates anniversaire)
Schedule::command('compte-ordinaire:process-daily')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/compte-ordinaire-daily.log'));
