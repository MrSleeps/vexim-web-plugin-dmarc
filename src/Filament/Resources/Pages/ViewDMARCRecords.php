<?php

namespace VEximweb\Plugin\DMARC\Filament\Resources\Pages;

use VEximweb\Plugin\DMARC\Filament\Resources\DMARCReportResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\Action;

class ViewDMARCRecords extends ViewRecord
{
    protected static string $resource = DMARCReportResource::class;
    
    /**
     * Get the record with eager loaded relationships
     */
    public function getRecord(): \Illuminate\Database\Eloquent\Model
    {
        $record = parent::getRecord();
        $record->load(['records.dkimResults', 'records.spfResults']);
        return $record;
    }
    
    public function table(Table $table): Table
    {
        return $table
            ->query($this->record->records()->with(['dkimResults', 'spfResults']))
            ->columns([
                TextColumn::make('source_ip')
                    ->label('Source IP')
                    ->searchable()
                    ->copyable(),
                
                TextColumn::make('count')
                    ->label('Count')
                    ->numeric()
                    ->sortable(),
                
                TextColumn::make('disposition')
                    ->label('Disposition')
                    ->badge()
                    ->color(function ($state) {
                        if ($state === 'none') return 'success';
                        if ($state === 'quarantine') return 'warning';
                        if ($state === 'reject') return 'danger';
                        return 'gray';
                    })
                    ->sortable(),
                
                TextColumn::make('dkim_result')
                    ->label('DKIM')
                    ->badge()
                    ->color(function ($state) {
                        if ($state === 'pass') return 'success';
                        if ($state === 'fail' || $state === 'softfail') return 'danger';
                        if ($state === 'neutral') return 'warning';
                        return 'gray';
                    })
                    ->sortable(),
                
                TextColumn::make('spf_result')
                    ->label('SPF')
                    ->badge()
                    ->color(function ($state) {
                        if ($state === 'pass') return 'success';
                        if ($state === 'fail' || $state === 'softfail') return 'danger';
                        if ($state === 'neutral') return 'warning';
                        return 'gray';
                    })
                    ->sortable(),
                
                TextColumn::make('is_compliant')
                    ->label('Compliant')
                    ->formatStateUsing(function ($state, $record) {
                        return $record->is_compliant ? '✅ Yes' : '❌ No';
                    })
                    ->badge()
                    ->color(function ($state, $record) {
                        return $record->is_compliant ? 'success' : 'danger';
                    }),
                
                TextColumn::make('created_at')
                    ->label('Imported')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('disposition')
                    ->options([
                        'none' => 'None',
                        'quarantine' => 'Quarantine',
                        'reject' => 'Reject',
                    ]),
                
                SelectFilter::make('dkim_result')
                    ->options([
                        'pass' => 'Pass',
                        'fail' => 'Fail',
                        'softfail' => 'Soft Fail',
                        'neutral' => 'Neutral',
                        'temperror' => 'Temp Error',
                        'permerror' => 'Perm Error',
                        'none' => 'None',
                    ]),
                
                SelectFilter::make('spf_result')
                    ->options([
                        'pass' => 'Pass',
                        'fail' => 'Fail',
                        'softfail' => 'Soft Fail',
                        'neutral' => 'Neutral',
                        'temperror' => 'Temp Error',
                        'permerror' => 'Perm Error',
                        'none' => 'None',
                    ]),
                
                Filter::make('compliant_only')
                    ->label('Compliant Only')
                    ->query(function (Builder $query): Builder {
                        return $query->where(function ($q) {
                            $q->where('disposition', 'none')
                                ->orWhere(function ($sub) {
                                    $sub->where('dkim_result', 'pass')
                                        ->where('spf_result', 'pass');
                                });
                        });
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
    
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back_to_report')
                ->label('Back to Report')
                ->icon('heroicon-o-arrow-left')
                ->url(DMARCReportResource::getUrl('view', ['record' => $this->record])),
            Action::make('export')
                ->label('Export Records')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    // Export logic
                }),
        ];
    }
}