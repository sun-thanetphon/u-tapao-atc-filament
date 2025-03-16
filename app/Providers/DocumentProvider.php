<?php

namespace App\Providers;

use App\Models\Document;
use App\Observers\DocumentObserver;
use Illuminate\Support\ServiceProvider;

class DocumentProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Document::observe(DocumentObserver::class);
    }
}