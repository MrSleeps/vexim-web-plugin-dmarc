<?php

namespace VEximweb\Plugin\DMARC\Filament\Resources\Pages;

use VEximweb\Plugin\DMARC\Filament\Resources\DMARCReportResource;
use VEximweb\Plugin\DMARC\Models\DMARCReport;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\CodeEntry;
use Phiki\Grammar\Grammar;
use Phiki\Theme\Theme;

class ViewDMARCReport extends ViewRecord
{
    protected static string $resource = DMARCReportResource::class;

    /**
     * Get the record with eager loaded relationships
     */
    public function getRecord(): DMARCReport
    {
        $record = parent::getRecord();

        if (!$record) {
            return $record;
        }

        // Eager load all relationships including nested ones
        $record->load([
            'policy',
            'records',
            'records.dkimResults',
            'records.spfResults',
        ]);

        return $record;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Report Summary')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('report_id')
                                    ->label('Report ID')
                                    ->copyable(),
                                TextEntry::make('domain')
                                    ->label('Domain')
                                    ->badge()
                                    ->color('primary'),
                                TextEntry::make('org_name')
                                    ->label('Organization'),
                            ]),
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('date_begin')
                                    ->label('Date Range')
                                    ->formatStateUsing(function ($state, $record) {
                                        return $record->date_begin->format('Y-m-d H:i:s') . ' → ' . $record->date_end->format('Y-m-d H:i:s');
                                    }),
                                TextEntry::make('compliance_rate')
                                    ->label('Compliance Rate')
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
                                    }),
                                TextEntry::make('records_count')
                                    ->label('Total Records')
                                    ->formatStateUsing(function ($state, $record) {
                                        return $record->records->count();
                                    }),
                            ]),
                    ]),

                Section::make('DMARC Policy')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('policy_p')
                                    ->label('Policy')
                                    ->state(function ($record) {
                                        // Get the policy data directly from the relationship
                                        $policy = $record->getRelation('policy');
                                        if (!$policy) {
                                            // Try to load it if not loaded
                                            $policy = $record->policy()->first();
                                        }
                                        return $policy ? strtoupper($policy->p) : 'N/A';
                                    })
                                    ->badge()
                                    ->color(function ($state, $record) {
                                        $policy = $record->getRelation('policy');
                                        if (!$policy) {
                                            $policy = $record->policy()->first();
                                        }
                                        if (!$policy) return 'gray';
                                        if ($policy->p === 'reject') return 'danger';
                                        if ($policy->p === 'quarantine') return 'warning';
                                        return 'success';
                                    }),
                                TextEntry::make('policy_sp')
                                    ->label('Subdomain Policy')
                                    ->state(function ($record) {
                                        $policy = $record->getRelation('policy');
                                        if (!$policy) {
                                            $policy = $record->policy()->first();
                                        }
                                        if (!$policy || !$policy->sp) return 'N/A';
                                        return strtoupper($policy->sp);
                                    })
                                    ->badge()
                                    ->color(function ($state, $record) {
                                        $policy = $record->getRelation('policy');
                                        if (!$policy) {
                                            $policy = $record->policy()->first();
                                        }
                                        if (!$policy || !$policy->sp) return 'gray';
                                        if ($policy->sp === 'reject') return 'danger';
                                        if ($policy->sp === 'quarantine') return 'warning';
                                        if ($policy->sp === 'none') return 'success';
                                        return 'gray';
                                    }),
                                TextEntry::make('policy_pct')
                                    ->label('Percentage')
                                    ->state(function ($record) {
                                        $policy = $record->getRelation('policy');
                                        if (!$policy) {
                                            $policy = $record->policy()->first();
                                        }
                                        return $policy ? $policy->pct . '%' : 'N/A';
                                    }),
                                TextEntry::make('policy_fo')
                                    ->label('Failure Options')
                                    ->state(function ($record) {
                                        $policy = $record->getRelation('policy');
                                        if (!$policy) {
                                            $policy = $record->policy()->first();
                                        }
                                        return $policy ? $policy->fo : 'N/A';
                                    })
                                    ->badge()
                                    ->color('info'),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('policy_adkim')
                                    ->label('DKIM Alignment')
                                    ->state(function ($record) {
                                        $policy = $record->getRelation('policy');
                                        if (!$policy) {
                                            $policy = $record->policy()->first();
                                        }
                                        if (!$policy) return 'N/A';
                                        return $policy->adkim === 'r' ? 'Relaxed (r)' : 'Strict (s)';
                                    })
                                    ->badge()
                                    ->color('info'),
                                TextEntry::make('policy_aspf')
                                    ->label('SPF Alignment')
                                    ->state(function ($record) {
                                        $policy = $record->getRelation('policy');
                                        if (!$policy) {
                                            $policy = $record->policy()->first();
                                        }
                                        if (!$policy) return 'N/A';
                                        return $policy->aspf === 'r' ? 'Relaxed (r)' : 'Strict (s)';
                                    })
                                    ->badge()
                                    ->color('info'),
                            ]),
                        TextEntry::make('policy_text')
                            ->label('DNS TXT Record')
                            ->state(function ($record) {
                                $policy = $record->getRelation('policy');
                                if (!$policy) {
                                    $policy = $record->policy()->first();
                                }
                                if (!$policy) return 'No policy found';
                                
                                $parts = [];
                                $parts[] = 'v=DMARC1';
                                $parts[] = 'p=' . ($policy->p ?? 'none');
                                if ($policy->sp) $parts[] = 'sp=' . $policy->sp;
                                if ($policy->pct) $parts[] = 'pct=' . $policy->pct;
                                if ($policy->adkim) $parts[] = 'adkim=' . $policy->adkim;
                                if ($policy->aspf) $parts[] = 'aspf=' . $policy->aspf;
                                if ($policy->fo) $parts[] = 'fo=' . $policy->fo;
                                return implode('; ', $parts);
                            })
                            ->badge()
                            ->color('gray')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                Section::make('Authentication Records')
                    ->schema([
                        RepeatableEntry::make('records')
                            ->label('Authentication Records')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        TextEntry::make('source_ip')
                                            ->label('Source IP')
                                            ->copyable(),
                                        TextEntry::make('count')
                                            ->label('Count')
                                            ->numeric(),
                                        TextEntry::make('disposition')
                                            ->label('Disposition')
                                            ->badge()
                                            ->color(function ($state) {
                                                if ($state === 'none') return 'success';
                                                if ($state === 'quarantine') return 'warning';
                                                if ($state === 'reject') return 'danger';
                                                return 'gray';
                                            }),
                                        TextEntry::make('dkim_result')
                                            ->label('DKIM Result')
                                            ->badge()
                                            ->color(function ($state) {
                                                if ($state === 'pass') return 'success';
                                                if ($state === 'fail') return 'danger';
                                                if ($state === 'neutral') return 'warning';
                                                return 'gray';
                                            }),
                                    ]),
                                Grid::make(1)
                                    ->schema([
                                        TextEntry::make('spf_result')
                                            ->label('SPF Result')
                                            ->badge()
                                            ->color(function ($state) {
                                                if ($state === 'pass') return 'success';
                                                if ($state === 'fail' || $state === 'softfail') return 'danger';
                                                if ($state === 'neutral') return 'warning';
                                                return 'gray';
                                            }),
                                    ]),

                                // DKIM Results Section
                                Section::make('DKIM Details')
                                    ->schema([
                                        RepeatableEntry::make('dkimResults')
                                            ->label('')
                                            ->schema([
                                                Grid::make(3)
                                                    ->schema([
                                                        TextEntry::make('domain')
                                                            ->label('Domain')
                                                            ->copyable(),
                                                        TextEntry::make('selector')
                                                            ->label('Selector')
                                                            ->copyable(),
                                                        TextEntry::make('result')
                                                            ->label('Result')
                                                            ->badge()
                                                            ->color(function ($state) {
                                                                if ($state === 'pass') return 'success';
                                                                if ($state === 'fail') return 'danger';
                                                                if ($state === 'neutral') return 'warning';
                                                                return 'gray';
                                                            }),
                                                    ]),
                                            ])
                                            ->columnSpanFull()
                                            ->hidden(fn ($record) => $record->dkimResults->isEmpty()),
                                    ])
                                    ->collapsible()
                                    ->collapsed(fn ($record) => $record->dkimResults->isEmpty())
                                    ->hidden(fn ($record) => $record->dkimResults->isEmpty()),

                                // SPF Results Section
                                Section::make('SPF Details')
                                    ->schema([
                                        RepeatableEntry::make('spfResults')
                                            ->label('')
                                            ->schema([
                                                Grid::make(3)
                                                    ->schema([
                                                        TextEntry::make('domain')
                                                            ->label('Domain')
                                                            ->copyable(),
                                                        TextEntry::make('scope')
                                                            ->label('Scope')
                                                            ->badge()
                                                            ->color('info'),
                                                        TextEntry::make('result')
                                                            ->label('Result')
                                                            ->badge()
                                                            ->color(function ($state) {
                                                                if ($state === 'pass') return 'success';
                                                                if ($state === 'fail' || $state === 'softfail') return 'danger';
                                                                if ($state === 'neutral') return 'warning';
                                                                return 'gray';
                                                            }),
                                                    ]),
                                            ])
                                            ->columnSpanFull()
                                            ->hidden(fn ($record) => $record->spfResults->isEmpty()),
                                    ])
                                    ->collapsible()
                                    ->collapsed(fn ($record) => $record->spfResults->isEmpty())
                                    ->hidden(fn ($record) => $record->spfResults->isEmpty()),
                            ])
                            ->columnSpanFull(),
                    ]),

                Section::make('Raw XML Data')
                    ->schema([
                        CodeEntry::make('raw_data')
                        ->label('')
                        ->grammar(Grammar::Xml)
                        ->columnSpanFull()
                        ->lightTheme(Theme::GithubLight)
                        ->darkTheme(Theme::GithubDark)
                        ->state(function ($record) {
                            $xml = $record->raw_data;

                            if (empty($xml)) {
                                return 'No raw data available';
                            }

                            $formatted = self::formatXml($xml);
                            return $formatted;
                            //return '<pre style="white-space: pre-wrap; font-size: 0.8rem;">' . e($formatted) . '</pre>';
                            }),
                    ])
                    ->collapsible()
                    ->collapsed(true),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_records')
                ->label('View All Records')
                ->icon('heroicon-o-list-bullet')
                ->url(DMARCReportResource::getUrl('records', ['record' => $this->record])),
            Action::make('export')
                ->label('Export Report')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    // Export logic
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * Pretty-print a raw XML string with indentation.
     * Falls back to returning the original string if it can't be parsed.
     */
    protected static function formatXml(string $xml): string
    {
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        // Suppress warnings from malformed XML and check the return value instead.
        $loaded = @$dom->loadXML($xml);

        if (!$loaded) {
            // Not valid XML (or already malformed) — show it as-is rather than erroring.
            return $xml;
        }

        return $dom->saveXML();
    }
}