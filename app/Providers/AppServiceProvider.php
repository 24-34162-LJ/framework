<?php

namespace App\Providers;

use App\Services\Identity\GeminiIdReader;
use App\Services\Identity\IdAnalyzerReader;
use App\Services\Identity\IdDocumentReader;
use App\Services\Identity\PhIdRules;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ID recognition: pick the reader from config so Gemini can be swapped (e.g. for Claude) in one place.
        $this->app->bind(IdDocumentReader::class, function ($app) {
            return match ((string) config('services.identity.reader', 'gemini')) {
                'gemini' => $app->make(GeminiIdReader::class),
                'idanalyzer' => $app->make(IdAnalyzerReader::class),
                default => throw new InvalidArgumentException('Unknown IDENTITY_READER: '.config('services.identity.reader')),
            };
        });

        $this->app->bind(PhIdRules::class, fn () => new PhIdRules(
            (float) config('services.identity.name_match_threshold', 0.85),
            (float) config('services.identity.min_confidence', 0.7),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
