<?php

namespace App\Providers;

use App\Services\Copyleaks\CopyleaksScanClient;
use App\Services\Copyleaks\FakeScanClient;
use App\Services\Copyleaks\ScanClient;
use App\Services\Manuscripts\DocxInspector;
use App\Services\Manuscripts\ManuscriptInspector;
use App\Services\Manuscripts\ManuscriptInspectorChain;
use App\Services\Manuscripts\PdfInspector;
use App\Services\Reports\Manuscript\PdfWordExtractor;
use App\Services\Reports\Manuscript\SmalotPdfWordExtractor;
use App\Services\Reports\Mapping\ReportIdFormatter;
use App\Services\Reports\MpdfReportRenderer;
use App\Services\Reports\MpdfTextMeasurer;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\TextMeasurer;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
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

        $this->app->bind(ReportIdFormatter::class, fn (): ReportIdFormatter => new ReportIdFormatter(
            (string) config('report.id_prefix'),
            (string) config('report.id_account'),
        ));

        $this->app->bind(ReportRenderer::class, MpdfReportRenderer::class);
        $this->app->bind(TextMeasurer::class, MpdfTextMeasurer::class);
        $this->app->bind(PdfWordExtractor::class, SmalotPdfWordExtractor::class);

        // Tests get the fake and can script it. Nothing else may ever reach Copyleaks from a test.
        $this->app->singleton(FakeScanClient::class);
        $this->app->bind(ScanClient::class, fn (Application $app): ScanClient => $app->environment('testing')
            ? $app->make(FakeScanClient::class)
            : $app->make(CopyleaksScanClient::class));
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

        // Submit spends credits, so it is limited tightly per address.
        RateLimiter::for('scan-submit', fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->ip()));

        // Copyleaks calls from shared addresses, so the webhook is limited per scan ID. A 429 just makes Copyleaks retry.
        RateLimiter::for('copyleaks-webhook', fn (Request $request): Limit => Limit::perMinute(30)->by('copyleaks-webhook:'.$request->route('scanId')));

        // A scan can have hundreds of result files pushed at once, so the export addresses get a wider limit (spec 0005).
        RateLimiter::for('copyleaks-export', fn (Request $request): Limit => Limit::perMinute(600)->by('copyleaks-export:'.$request->route('scanId')));
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
