<?php

use App\Services\Identity\IdentityVerificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('identity:purge-files', function (IdentityVerificationService $service) {
    $this->info('Deleted '.$service->purgeExpiredFiles().' expired ID image(s).');
})->purpose('Delete client ID images past their retention period (Data Privacy Act)');

Schedule::command('identity:purge-files')->daily();
