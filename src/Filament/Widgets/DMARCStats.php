<?php

namespace VEximweb\Plugin\DMARC\Filament\Widgets;

use VEximweb\Plugin\DMARC\Services\DMARCStatsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DMARCStats extends StatsOverviewWidget
{
    protected static ?int $sort = 3;
    
    /**
     * Get the widget heading/title
     */
    protected function getHeading(): string
    {
        return 'DMARC Compliance Stats';
    }
    
    /**
     * Get the widget description (optional)
     */
    protected function getDescription(): ?string
    {
        $user = Auth::user();
        
        if (!$user) {
            return null;
        }
        
        if ($user->hasRole('system_admin')) {
            return 'Overview of all monitored domains';
        }
        
        if ($user->hasRole('domain_admin')) {
            return 'Overview of your managed domains';
        }
        
        return null;
    }
    
    /**
     * Determine if the widget can be viewed
     */
    public static function canView(): bool
    {
        return true;
    }
    
    protected function getStats(): array
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return [];
            }
            
            $statsService = app(DMARCStatsService::class);
            $dashboard = $statsService->getDashboardStats();
            
            // Get summary stats
            $summary = $dashboard['summary'] ?? $this->getDefaultSummary();
            $domainStats = $dashboard['domain_stats'] ?? [];
            $todayStats = $domainStats['today'] ?? $this->getDefaultDomainStats();
            $complianceStats = $dashboard['compliance_stats'] ?? $this->getDefaultComplianceStats();
            
            // Extract values
            $totalDomains = $summary['total_domains'] ?? 0;
            $totalMessages = $summary['total_messages'] ?? 0;
            $avgCompliance = $summary['avg_compliance_rate'] ?? 0;
            
            $todayMessages = $todayStats['total_messages'] ?? 0;
            $todayCompliance = $todayStats['compliance_rate'] ?? 0;
            
            $excellent = $complianceStats['excellent'] ?? 0;
            $good = $complianceStats['good'] ?? 0;
            $fair = $complianceStats['fair'] ?? 0;
            $poor = $complianceStats['poor'] ?? 0;
            
            // System admin sees everything
            if ($user->hasRole('system_admin')) {
                return [
                    Stat::make('Total Domains', number_format($totalDomains))
                        ->description('Monitored domains')
                        ->icon('heroicon-o-globe-alt')
                        ->color('primary'),
                    
                    Stat::make('Total Messages', number_format($totalMessages))
                        ->description('All DMARC reports')
                        ->icon('heroicon-o-envelope')
                        ->color('primary'),
                    
                    Stat::make('Avg Compliance', number_format($avgCompliance, 1) . '%')
                        ->description('Overall compliance rate')
                        ->icon('heroicon-o-check-circle')
                        ->color($this->getComplianceColor($avgCompliance)),
                    
                    Stat::make('Domain Status', $this->getDomainStatusText($excellent, $good, $fair, $poor))
                        ->description($this->getDomainStatusDetail($excellent, $good, $fair, $poor))
                        ->icon('heroicon-o-chart-bar')
                        ->color($this->getDomainStatusColor($excellent, $good, $fair, $poor)),
                ];
            }
            
            // Domain admin sees their domains' stats
            if ($user->hasRole('domain_admin')) {
                $accessibleDomains = $statsService->getAccessibleDomains();
                $domainCount = count($accessibleDomains ?? []);
                
                return [
                    Stat::make('Your Domains', number_format($domainCount))
                        ->description('Domains you manage')
                        ->icon('heroicon-o-globe-alt')
                        ->color('primary'),
                    
                    Stat::make('Today\'s Messages', number_format($todayMessages))
                        ->description('DMARC reports today')
                        ->icon('heroicon-o-envelope')
                        ->color('primary'),
                    
                    Stat::make('Today\'s Compliance', number_format($todayCompliance, 1) . '%')
                        ->description('Today\'s compliance rate')
                        ->icon('heroicon-o-check-circle')
                        ->color($this->getComplianceColor($todayCompliance)),
                    
                    Stat::make('Domain Health', $this->getDomainStatusText($excellent, $good, $fair, $poor))
                        ->description($this->getDomainStatusDetail($excellent, $good, $fair, $poor))
                        ->icon('heroicon-o-heart')
                        ->color($this->getDomainStatusColor($excellent, $good, $fair, $poor)),
                ];
            }
            
            // Domain user sees nothing (or limited stats)
            if ($user->hasRole('domain_user')) {
                return [
                    Stat::make('DMARC Reports', 'No Access')
                        ->description('Domain users cannot view DMARC statistics')
                        ->icon('heroicon-o-lock-closed')
                        ->color('secondary'),
                    
                    Stat::make('Status', 'Contact Administrator')
                        ->description('DMARC stats are for domain admins only')
                        ->icon('heroicon-o-information-circle')
                        ->color('warning'),
                ];
            }
            
            return [];
            
        } catch (\Exception $e) {
            Log::error('DMARCStats widget error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return [
                Stat::make('Error', 'Unable to load DMARC stats')
                    ->description('Please try again later')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger'),
            ];
        }
    }
    
    /**
     * Get color for compliance rate
     */
    protected function getComplianceColor(float $rate): string
    {
        if ($rate >= 90) return 'success';
        if ($rate >= 70) return 'warning';
        if ($rate >= 50) return 'info';
        return 'danger';
    }
    
    /**
     * Get domain status text
     */
    protected function getDomainStatusText(int $excellent, int $good, int $fair, int $poor): string
    {
        $total = $excellent + $good + $fair + $poor;
        if ($total === 0) return 'No Domains';
        
        $excellentPercent = round(($excellent / $total) * 100);
        return $excellentPercent . '% Excellent';
    }
    
    /**
     * Get domain status detail
     */
    protected function getDomainStatusDetail(int $excellent, int $good, int $fair, int $poor): string
    {
        $parts = [];
        if ($excellent > 0) $parts[] = "{$excellent} Excellent";
        if ($good > 0) $parts[] = "{$good} Good";
        if ($fair > 0) $parts[] = "{$fair} Fair";
        if ($poor > 0) $parts[] = "{$poor} Poor";
        return implode(' | ', $parts) ?: 'No data';
    }
    
    /**
     * Get domain status color
     */
    protected function getDomainStatusColor(int $excellent, int $good, int $fair, int $poor): string
    {
        $total = $excellent + $good + $fair + $poor;
        if ($total === 0) return 'secondary';
        
        $excellentPercent = ($excellent / $total) * 100;
        
        if ($excellentPercent >= 70) return 'success';
        if ($excellentPercent >= 40) return 'warning';
        return 'danger';
    }
    
    /**
     * Get default summary
     */
    protected function getDefaultSummary(): array
    {
        return [
            'total_domains' => 0,
            'total_messages' => 0,
            'avg_compliance_rate' => 0,
            'domains_excellent' => 0,
            'domains_good' => 0,
            'domains_fair' => 0,
            'domains_poor' => 0,
        ];
    }
    
    /**
     * Get default domain stats
     */
    protected function getDefaultDomainStats(): array
    {
        return [
            'total_messages' => 0,
            'pass_count' => 0,
            'quarantine_count' => 0,
            'reject_count' => 0,
            'compliance_rate' => 0,
        ];
    }
    
    /**
     * Get default compliance stats
     */
    protected function getDefaultComplianceStats(): array
    {
        return [
            'excellent' => 0,
            'good' => 0,
            'fair' => 0,
            'poor' => 0,
            'total_domains' => 0,
        ];
    }
}