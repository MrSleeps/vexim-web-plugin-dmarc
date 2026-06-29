<?php
// plugins/vexim-web-plugin-dmarc/src/Filament/Resources/DMARCReportResource.php

namespace VEximweb\Plugin\DMARC\Filament\Resources;

use VEximweb\Plugin\DMARC\Filament\Resources\Pages;
use VEximweb\Plugin\DMARC\Filament\Resources\Schemas\DMARCReportForm;
use VEximweb\Plugin\DMARC\Models\DMARCReport;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;

class DMARCReportResource extends Resource
{
    protected static ?string $model = DMARCReport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports & Analytics';

    protected static ?string $navigationLabel = 'DMARC';

    protected static ?int $navigationSort = 1;

    public static function getLabel(): string
    {
        return 'DMARC Report';
    }

    public static function getPluralLabel(): string
    {
        return 'DMARC Reports';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        // System admin sees everything
        if ($user->hasRole('system_admin')) {
            return $query;
        }

        // Domain admin sees only their domains
        if ($user->hasRole('domain_admin')) {
            $domains = $user->domains()->pluck('domain')->toArray();
            return $query->whereIn('domain', $domains);
        }

        // Domain user sees nothing
        if ($user->hasRole('domain_user')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return DMARCReportForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('report_id')
                    ->label('Report ID')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        if (strlen($state) > 30) {
                            return $state;
                        }
                        return null;
                    }),

                TextColumn::make('domain')
                    ->label('Domain')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('org_name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('date_begin')
                    ->label('Date Range')
                    ->formatStateUsing(function ($state, $record) {
                        return $record->date_begin->format('Y-m-d') . ' → ' . $record->date_end->format('Y-m-d');
                    })
                    ->sortable(),

                TextColumn::make('records_count')
                    ->label('Records')
                    ->counts('records')
                    ->sortable(),

                TextColumn::make('compliance_rate')
                    ->label('Compliance')
                    ->formatStateUsing(function ($state, $record) {
                        return number_format($record->compliance_rate, 1) . '%';
                    })
                    ->badge()
                    ->color(function ($state, $record) {
                        $rate = $record->compliance_rate;
                        if ($rate >= 90) return 'success';
                        if ($rate >= 70) return 'warning';
                        if ($rate >= 50) return 'info';
                        return 'danger';
                    })
                    ->sortable(),

                TextColumn::make('policy.p')
                    ->label('Policy')
                    ->formatStateUsing(function ($state, $record) {
                        return strtoupper($record->policy?->p ?? 'N/A');
                    })
                    ->badge()
                    ->color(function ($state, $record) {
                        $policy = $record->policy?->p ?? 'none';
                        if ($policy === 'reject') return 'danger';
                        if ($policy === 'quarantine') return 'warning';
                        return 'success';
                    }),

                TextColumn::make('created_at')
                    ->label('Imported')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('domain')
                    ->label('Domain')
                    ->options(function () {
                        $user = Auth::user();
                        if ($user->hasRole('system_admin')) {
                            return DMARCReport::distinct()->pluck('domain', 'domain')->toArray();
                        }
                        if ($user->hasRole('domain_admin')) {
                            $domains = $user->domains()->pluck('domain')->toArray();
                            return DMARCReport::whereIn('domain', $domains)
                                ->distinct()
                                ->pluck('domain', 'domain')
                                ->toArray();
                        }
                        return [];
                    })
                    ->multiple()
                    ->searchable(),

                SelectFilter::make('org_name')
                    ->label('Organization')
                    ->options(function () {
                        return DMARCReport::distinct()->pluck('org_name', 'org_name')->toArray();
                    })
                    ->multiple()
                    ->searchable(),

                Filter::make('date_range')
                    ->schema([
                        DatePicker::make('date_from')
                            ->label('From'),
                        DatePicker::make('date_to')
                            ->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['date_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date_begin', '>=', $date),
                            )
                            ->when(
                                $data['date_to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date_end', '<=', $date),
                            );
                    }),

                SelectFilter::make('policy')
                    ->label('Policy')
                    ->options([
                        'none' => 'None',
                        'quarantine' => 'Quarantine',
                        'reject' => 'Reject',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }
                        return $query->whereHas('policy', function ($q) use ($data) {
                            $q->where('p', $data['value']);
                        });
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (DMARCReport $record): string => DMARCReportResource::getUrl('view', ['record' => $record])),
                Action::make('view_records')
                    ->label('View Records')
                    ->icon('heroicon-o-list-bullet')
                    ->url(fn (DMARCReport $record): string => DMARCReportResource::getUrl('records', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('mark_exported')
                        ->label('Mark as Exported')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($records) {
                            // Logic to mark as exported
                        }),
                ]),
            ])
            ->defaultSort('date_begin', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            // You can add relation managers here if needed
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDMARCReports::route('/'),
            'view' => Pages\ViewDMARCReport::route('/{record}'),
            'records' => Pages\ViewDMARCRecords::route('/{record}/records'),
        ];
    }
}