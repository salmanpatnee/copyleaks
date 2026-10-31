<?php

namespace App\Providers;

use App\Services\Manuscripts\DocxInspector;
use App\Services\Manuscripts\ManuscriptInspector;
use App\Services\Manuscripts\ManuscriptInspectorChain;
use App\Services\Manuscripts\PdfInspector;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ManuscriptInspector::class, fn (): ManuscriptInspector => new ManuscriptInspectorChain([
            $this->app->make(PdfInspector::class),
            $this->app->make(DocxInspector::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureTrustedProxies();
    }

    /** Behind a proxy such as Cloudflare, trust it so the throttle sees real addresses (TRUSTED_PROXIES). */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : explode(',', $proxies));
        }
    }

    /** The upload route is open in Slice 0, so it is limited per address. */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
