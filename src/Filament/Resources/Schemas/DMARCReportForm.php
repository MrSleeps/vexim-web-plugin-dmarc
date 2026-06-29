<?php
// plugins/vexim-web-plugin-dmarc/src/Filament/Resources/DMARCReportResource/Schemas/DMARCReportForm.php

namespace VEximweb\Plugin\DMARC\Filament\Resources\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;


class DMARCReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Report Metadata')
                    ->schema([
                        TextInput::make('report_id')
                            ->label('Report ID')
                            ->disabled(),
                        TextInput::make('org_name')
                            ->label('Organization')
                            ->disabled(),
                        TextInput::make('email')
                            ->label('Email')
                            ->disabled(),
                        TextInput::make('domain')
                            ->label('Domain')
                            ->disabled(),
                        DateTimePicker::make('date_begin')
                            ->label('Date Range Start')
                            ->disabled(),
                        DateTimePicker::make('date_end')
                            ->label('Date Range End')
                            ->disabled(),
                    ])->columns(2),

                Section::make('Policy')
                    ->schema([
                        TextInput::make('policy.domain')
                            ->label('Domain')
                            ->disabled(),
                        Select::make('policy.p')
                            ->label('Policy')
                            ->options([
                                'none' => 'None',
                                'quarantine' => 'Quarantine',
                                'reject' => 'Reject',
                            ])
                            ->disabled(),
                        Select::make('policy.sp')
                            ->label('Subdomain Policy')
                            ->options([
                                'none' => 'None',
                                'quarantine' => 'Quarantine',
                                'reject' => 'Reject',
                            ])
                            ->disabled(),
                        TextInput::make('policy.pct')
                            ->label('Percentage')
                            ->disabled(),
                        Select::make('policy.adkim')
                            ->label('DKIM Alignment')
                            ->options([
                                'r' => 'Relaxed',
                                's' => 'Strict',
                            ])
                            ->disabled(),
                        Select::make('policy.aspf')
                            ->label('SPF Alignment')
                            ->options([
                                'r' => 'Relaxed',
                                's' => 'Strict',
                            ])
                            ->disabled(),
                    ])->columns(2),
            ]);
    }
}