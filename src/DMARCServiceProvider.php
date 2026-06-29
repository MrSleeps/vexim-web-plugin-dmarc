<?php
namespace VEximweb\Plugin\DMARC;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Console\Scheduling\Schedule;
use Filament\Panel;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCReportRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\DMARCReportRepository;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCPolicyRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\DMARCPolicyRepository;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCRecordRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\DMARCRecordRepository;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCDKIMResultRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\DMARCDKIMResultRepository;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCSPFResultRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\DMARCSPFResultRepository;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCStatisticsRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\DMARCStatisticsRepository;
use VEximweb\Plugin\DMARC\Console\Commands\FetchRecentEmails;
use VEximweb\Plugin\DMARC\Console\Commands\SetupDMARCAliases;



class DMARCServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind all repositories
        $this->app->bind(DMARCReportRepositoryInterface::class, DMARCReportRepository::class);
        $this->app->bind(DMARCPolicyRepositoryInterface::class, DMARCPolicyRepository::class);
        $this->app->bind(DMARCRecordRepositoryInterface::class, DMARCRecordRepository::class);
        $this->app->bind(DMARCDKIMResultRepositoryInterface::class, DMARCDKIMResultRepository::class);
        $this->app->bind(DMARCSPFResultRepositoryInterface::class, DMARCSPFResultRepository::class);
        $this->app->bind(DMARCStatisticsRepositoryInterface::class, DMARCStatisticsRepository::class);
        
        // Merge DMARC config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/dmarc.php', 
            'dmarc'
        );

        Panel::configureUsing(function (Panel $panel) {
            $panel->plugin(DMARCPlugin::make());
        });
    }

    public function boot(): void
    {
        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('vw:fetch-dmarc-emails')->hourly();
            });            
            $this->commands([
                FetchRecentEmails::class,
                SetupDMARCAliases::class,
            ]);
        }

        // Your existing DNS client setup
        $this->app->singleton(DnsClientFactory::class, function ($app) {
            $factory = new DnsClientFactory();

            if (class_exists(RegisterDnsClients::class)) {
                Event::dispatch(new RegisterDnsClients($factory));
            }

            return $factory;
        });
    }
}