<?php

namespace VEximweb\Plugin\DMARC\Filament\Resources\Pages;

use VEximweb\Plugin\DMARC\Filament\Resources\DMARCReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDMARCReports extends ListRecords
{
    protected static string $resource = DMARCReportResource::class;
    
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh Reports')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    // You could trigger the fetch command here
                    // Artisan::call('vw:fetch-dmarc-emails');
                }),
        ];
    }
}
