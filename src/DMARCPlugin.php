<?php

namespace VEximweb\Plugin\DMARC;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Support\Facades\File;
use VEximweb\Plugin\DMARC\Filament\Resources\DMARCReportResource;

class DMARCPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'dmarc';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            DMARCReportResource::class,
        ]);        
        
        $widgetPath = __DIR__ . '/Filament/Widgets';
        if (is_dir($widgetPath)) {
            $widgetClasses = $this->discoverWidgets($widgetPath);
            $panel->widgets($widgetClasses);
        }   
        
     
    }

    public function boot(Panel $panel): void {}
    
    protected function discoverWidgets(string $path): array
    {
        $widgets = [];
        $files = File::allFiles($path);
        
        foreach ($files as $file) {
            $class = 'VEximweb\\Plugin\\DMARC\\Filament\\Widgets\\' . $file->getFilenameWithoutExtension();
            if (class_exists($class)) {
                $widgets[] = $class;
            }
        }
        
        return $widgets;
    }     
}
