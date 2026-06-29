<?php
namespace VEximweb\Plugin\DMARC\Services;

use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCReportRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCRecordRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCStatisticsRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCPolicyRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DMARCStatsService
{
    protected DMARCReportRepositoryInterface $reportRepository;
    protected DMARCRecordRepositoryInterface $recordRepository;
    protected DMARCStatisticsRepositoryInterface $statisticsRepository;
    protected DMARCPolicyRepositoryInterface $policyRepository;

    public function __construct(
        DMARCReportRepositoryInterface $reportRepository,
        DMARCRecordRepositoryInterface $recordRepository,
        DMARCStatisticsRepositoryInterface $statisticsRepository,
        DMARCPolicyRepositoryInterface $policyRepository
    ) {
        $this->reportRepository = $reportRepository;
        $this->recordRepository = $recordRepository;
        $this->statisticsRepository = $statisticsRepository;
        $this->policyRepository = $policyRepository;
    }

    /**
     * Get domains accessible to the current user based on their role
     */
    public function getAccessibleDomains(): ?array
    {
        $user = Auth::user();
        
        if (!$user) {
            return [];
        }
        
        // System admin sees everything
        if ($user->hasRole('system_admin')) {
            return null; // null means all domains
        }
        
        // Domain admin sees their assigned domains
        if ($user->hasRole('domain_admin')) {
            return $user->domains()->pluck('domain')->toArray();
        }
        
        // Domain user sees nothing (only their own email stats if implemented)
        if ($user->hasRole('domain_user')) {
            return []; // Empty array means no domains
        }
        
        return [];
    }

    /**
     * Get dashboard stats based on user role
     */
    public function getDashboardStats(): array
    {
        $user = Auth::user();
        
        if (!$user) {
            return $this->getEmptyDashboardStats();
        }
        
        $domains = $this->getAccessibleDomains();
        
        // Domain user or no access - return empty stats
        if ($domains === [] || $domains === null && !$user->hasRole('system_admin')) {
            return $this->getEmptyDashboardStats();
        }
        
        // System admin sees all stats
        if ($domains === null) {
            return $this->getFullDashboardStats();
        }
        
        // Domain admin sees filtered stats
        return $this->getFilteredDashboardStats($domains);
    }

    /**
     * Get full dashboard stats (system admin)
     */
    protected function getFullDashboardStats(): array
    {
        $now = Carbon::now();
        
        return [
            'summary' => $this->getGlobalSummary(),
            'domain_stats' => [
                'today' => $this->getDomainStatsForPeriod($now->copy()->startOfDay(), $now->copy()->endOfDay()),
                'week' => $this->getDomainStatsForPeriod($now->copy()->startOfWeek(), $now->copy()->endOfWeek()),
                'month' => $this->getDomainStatsForPeriod($now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
                'year' => $this->getDomainStatsForPeriod($now->copy()->startOfYear(), $now->copy()->endOfYear()),
            ],
            'compliance_trend' => $this->getComplianceTrend(30),
            'top_failing_domains' => $this->getTopFailingDomains(10),
            'top_failing_ips' => $this->getTopFailingIps(10),
            'policy_distribution' => $this->getPolicyDistribution(),
            'authentication_breakdown' => $this->getAuthenticationBreakdown(30),
            'recent_reports' => $this->getRecentReports(10),
            'compliance_stats' => $this->getComplianceStats(),
        ];
    }

    /**
     * Get filtered dashboard stats for specific domains
     */
    protected function getFilteredDashboardStats(array $domains): array
    {
        $now = Carbon::now();
        
        return [
            'summary' => $this->getFilteredSummary($domains),
            'domain_stats' => [
                'today' => $this->getDomainStatsForPeriod($now->copy()->startOfDay(), $now->copy()->endOfDay(), $domains),
                'week' => $this->getDomainStatsForPeriod($now->copy()->startOfWeek(), $now->copy()->endOfWeek(), $domains),
                'month' => $this->getDomainStatsForPeriod($now->copy()->startOfMonth(), $now->copy()->endOfMonth(), $domains),
                'year' => $this->getDomainStatsForPeriod($now->copy()->startOfYear(), $now->copy()->endOfYear(), $domains),
            ],
            'compliance_trend' => $this->getComplianceTrend(30, $domains),
            'top_failing_domains' => $this->getTopFailingDomains(10, $domains),
            'top_failing_ips' => $this->getTopFailingIps(10, $domains),
            'policy_distribution' => $this->getPolicyDistribution($domains),
            'authentication_breakdown' => $this->getAuthenticationBreakdown(30, $domains),
            'recent_reports' => $this->getRecentReports(10, $domains),
            'compliance_stats' => $this->getComplianceStats($domains),
        ];
    }

    /**
     * Get global summary
     */
    protected function getGlobalSummary(): array
    {
        $stats = $this->statisticsRepository->getGlobalStats();
        
        return [
            'total_domains' => $stats['total_domains'] ?? 0,
            'total_messages' => $stats['total_messages'] ?? 0,
            'avg_compliance_rate' => $stats['avg_compliance_rate'] ?? 0,
            'domains_excellent' => $stats['domains_with_excellent'] ?? 0,
            'domains_good' => $stats['domains_with_good'] ?? 0,
            'domains_fair' => $stats['domains_with_fair'] ?? 0,
            'domains_poor' => $stats['domains_with_poor'] ?? 0,
        ];
    }

    /**
     * Get filtered summary for specific domains
     */
    protected function getFilteredSummary(array $domains): array
    {
        $stats = $this->statisticsRepository->getByDomain($domains[0] ?? '');
        // Aggregate stats for multiple domains
        $totalMessages = 0;
        $totalCompliance = 0;
        $count = 0;
        $excellent = 0;
        $good = 0;
        $fair = 0;
        $poor = 0;

        foreach ($domains as $domain) {
            $domainStats = $this->statisticsRepository->getLatestByDomain($domain);
            if ($domainStats) {
                $totalMessages += $domainStats->total_messages;
                $totalCompliance += $domainStats->compliance_rate;
                $count++;
                
                $level = $domainStats->compliance_level;
                if ($level === 'excellent') $excellent++;
                elseif ($level === 'good') $good++;
                elseif ($level === 'fair') $fair++;
                else $poor++;
            }
        }

        return [
            'total_domains' => count($domains),
            'total_messages' => $totalMessages,
            'avg_compliance_rate' => $count > 0 ? round($totalCompliance / $count, 2) : 0,
            'domains_excellent' => $excellent,
            'domains_good' => $good,
            'domains_fair' => $fair,
            'domains_poor' => $poor,
        ];
    }

    /**
     * Get domain stats for a period
     */
    protected function getDomainStatsForPeriod($startDate, $endDate, ?array $domains = null): array
    {
        $stats = $this->statisticsRepository->getByDomain($domains[0] ?? '');
        
        // If specific domains, aggregate
        if ($domains) {
            $total = 0;
            $pass = 0;
            $quarantine = 0;
            $reject = 0;
            
            foreach ($domains as $domain) {
                $domainStats = $this->statisticsRepository->getByDomain($domain, 1);
                if ($domainStats->isNotEmpty()) {
                    $latest = $domainStats->first();
                    $total += $latest->total_messages;
                    $pass += $latest->pass_count;
                    $quarantine += $latest->quarantine_count;
                    $reject += $latest->reject_count;
                }
            }
            
            return [
                'total_messages' => $total,
                'pass_count' => $pass,
                'quarantine_count' => $quarantine,
                'reject_count' => $reject,
                'compliance_rate' => $total > 0 ? round(($pass / $total) * 100, 2) : 0,
                'period' => $startDate->toDateString() . ' to ' . $endDate->toDateString(),
            ];
        }
        
        // Global stats
        $globalStats = $this->statisticsRepository->getGlobalStats();
        return [
            'total_messages' => $globalStats['total_messages'] ?? 0,
            'pass_count' => 0, // Would need to aggregate
            'quarantine_count' => 0,
            'reject_count' => 0,
            'compliance_rate' => $globalStats['avg_compliance_rate'] ?? 0,
            'period' => $startDate->toDateString() . ' to ' . $endDate->toDateString(),
        ];
    }

    /**
     * Get compliance trend
     */
    protected function getComplianceTrend(int $days = 30, ?array $domains = null): array
    {
        $trend = [];
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays($days);
        
        if ($domains) {
            $domain = $domains[0] ?? '';
            $trendData = $this->statisticsRepository->getDailyTrend($domain, $days);
        } else {
            // Get all domains and aggregate
            $allDomains = $this->statisticsRepository->getDomainsWithLowCompliance(0);
            $trendData = collect();
            foreach ($allDomains as $domain) {
                $domainTrend = $this->statisticsRepository->getDailyTrend($domain->domain, $days);
                $trendData = $trendData->merge($domainTrend);
            }
            $trendData = $trendData->groupBy('date')->map(function ($group) {
                return [
                    'date' => $group->first()->date,
                    'compliance_rate' => $group->avg('compliance_rate'),
                    'total_messages' => $group->sum('total_messages'),
                ];
            })->values();
        }
        
        foreach ($trendData as $day) {
            $trend[] = [
                'date' => $day['date'],
                'compliance_rate' => round($day['compliance_rate'] ?? 0, 2),
                'total_messages' => $day['total_messages'] ?? 0,
            ];
        }
        
        return $trend;
    }

    /**
     * Get top failing domains
     */
    protected function getTopFailingDomains(int $limit = 10, ?array $domains = null): array
    {
        $lowCompliance = $this->statisticsRepository->getDomainsWithLowCompliance(90);
        
        if ($domains) {
            $lowCompliance = $lowCompliance->filter(function ($item) use ($domains) {
                return in_array($item->domain, $domains);
            });
        }
        
        return $lowCompliance->take($limit)->map(function ($item) {
            return [
                'domain' => $item->domain,
                'compliance_rate' => $item->compliance_rate,
                'total_messages' => $item->total_messages,
                'level' => $this->getComplianceLevel($item->compliance_rate),
            ];
        })->toArray();
    }

    /**
     * Get top failing IPs
     */
    protected function getTopFailingIps(int $limit = 10, ?array $domains = null): array
    {
        if ($domains) {
            $domain = $domains[0] ?? '';
            return $this->recordRepository->getTopFailingIps($domain, 30, $limit)->toArray();
        }
        
        // For system admin, aggregate across all domains
        // This would need a more complex query, but for now return empty
        return [];
    }

    /**
     * Get policy distribution
     */
    protected function getPolicyDistribution(?array $domains = null): array
    {
        $policies = $this->policyRepository->getByDomain($domains[0] ?? '');
        
        $distribution = [
            'reject' => 0,
            'quarantine' => 0,
            'none' => 0,
        ];
        
        if ($domains) {
            foreach ($domains as $domain) {
                $policy = $this->policyRepository->getByDomain($domain)->first();
                if ($policy) {
                    $distribution[$policy->p] = ($distribution[$policy->p] ?? 0) + 1;
                }
            }
        } else {
            // All domains - would need to query all policies
            $allPolicies = $this->policyRepository->getByDomain(''); // Would need a method to get all
            foreach ($allPolicies as $policy) {
                $distribution[$policy->p] = ($distribution[$policy->p] ?? 0) + 1;
            }
        }
        
        return $distribution;
    }

    /**
     * Get authentication breakdown
     */
    protected function getAuthenticationBreakdown(int $days = 30, ?array $domains = null): array
    {
        $breakdown = [
            'pass' => 0,
            'fail_both' => 0,
            'fail_dkim' => 0,
            'fail_spf' => 0,
            'partial' => 0,
            'neutral' => 0,
        ];
        
        if ($domains) {
            foreach ($domains as $domain) {
                $stats = $this->recordRepository->getAuthenticationStats($domain, $days);
                // Add to breakdown
                $breakdown['pass'] += $stats['pass_count'] ?? 0;
                // Other stats would need to be calculated
            }
        } else {
            // All domains - aggregate
            $allDomains = $this->statisticsRepository->getDomainsWithLowCompliance(0);
            foreach ($allDomains as $domain) {
                $stats = $this->recordRepository->getAuthenticationStats($domain->domain, $days);
                $breakdown['pass'] += $stats['pass_count'] ?? 0;
            }
        }
        
        return $breakdown;
    }

    /**
     * Get recent reports
     */
    protected function getRecentReports(int $limit = 10, ?array $domains = null): array
    {
        $reports = [];
        
        if ($domains) {
            foreach ($domains as $domain) {
                $domainReports = $this->reportRepository->getByDomain($domain, $limit);
                foreach ($domainReports as $report) {
                    $reports[] = [
                        'report_id' => $report->report_id,
                        'domain' => $report->domain,
                        'org_name' => $report->org_name,
                        'date_begin' => $report->date_begin->toDateString(),
                        'compliance_rate' => $report->compliance_rate,
                        'total_records' => $report->records->count(),
                        'policy' => $report->policy?->p ?? 'unknown',
                    ];
                }
            }
            // Sort by date descending and take limit
            usort($reports, function ($a, $b) {
                return strtotime($b['date_begin']) - strtotime($a['date_begin']);
            });
            $reports = array_slice($reports, 0, $limit);
        } else {
            // All domains
            $allReports = $this->reportRepository->getLatestReports($limit);
            foreach ($allReports as $report) {
                $reports[] = [
                    'report_id' => $report->report_id,
                    'domain' => $report->domain,
                    'org_name' => $report->org_name,
                    'date_begin' => $report->date_begin->toDateString(),
                    'compliance_rate' => $report->compliance_rate,
                    'total_records' => $report->records->count(),
                    'policy' => $report->policy?->p ?? 'unknown',
                ];
            }
        }
        
        return $reports;
    }

    /**
     * Get compliance stats
     */
    protected function getComplianceStats(?array $domains = null): array
    {
        $stats = [
            'excellent' => 0,
            'good' => 0,
            'fair' => 0,
            'poor' => 0,
            'total_domains' => 0,
        ];
        
        if ($domains) {
            foreach ($domains as $domain) {
                $domainStats = $this->statisticsRepository->getLatestByDomain($domain);
                if ($domainStats) {
                    $stats['total_domains']++;
                    $level = $domainStats->compliance_level;
                    if ($level === 'excellent') $stats['excellent']++;
                    elseif ($level === 'good') $stats['good']++;
                    elseif ($level === 'fair') $stats['fair']++;
                    else $stats['poor']++;
                }
            }
        } else {
            // All domains
            $globalStats = $this->statisticsRepository->getGlobalStats();
            $stats['excellent'] = $globalStats['domains_with_excellent'] ?? 0;
            $stats['good'] = $globalStats['domains_with_good'] ?? 0;
            $stats['fair'] = $globalStats['domains_with_fair'] ?? 0;
            $stats['poor'] = $globalStats['domains_with_poor'] ?? 0;
            $stats['total_domains'] = $globalStats['total_domains'] ?? 0;
        }
        
        return $stats;
    }

    /**
     * Get compliance level based on rate
     */
    protected function getComplianceLevel(float $rate): string
    {
        if ($rate >= 90) return 'excellent';
        if ($rate >= 70) return 'good';
        if ($rate >= 50) return 'fair';
        return 'poor';
    }

    /**
     * Get empty dashboard stats
     */
    protected function getEmptyDashboardStats(): array
    {
        return [
            'summary' => [
                'total_domains' => 0,
                'total_messages' => 0,
                'avg_compliance_rate' => 0,
                'domains_excellent' => 0,
                'domains_good' => 0,
                'domains_fair' => 0,
                'domains_poor' => 0,
            ],
            'domain_stats' => [
                'today' => ['total_messages' => 0, 'pass_count' => 0, 'quarantine_count' => 0, 'reject_count' => 0, 'compliance_rate' => 0],
                'week' => ['total_messages' => 0, 'pass_count' => 0, 'quarantine_count' => 0, 'reject_count' => 0, 'compliance_rate' => 0],
                'month' => ['total_messages' => 0, 'pass_count' => 0, 'quarantine_count' => 0, 'reject_count' => 0, 'compliance_rate' => 0],
                'year' => ['total_messages' => 0, 'pass_count' => 0, 'quarantine_count' => 0, 'reject_count' => 0, 'compliance_rate' => 0],
            ],
            'compliance_trend' => [],
            'top_failing_domains' => [],
            'top_failing_ips' => [],
            'policy_distribution' => ['reject' => 0, 'quarantine' => 0, 'none' => 0],
            'authentication_breakdown' => ['pass' => 0, 'fail_both' => 0, 'fail_dkim' => 0, 'fail_spf' => 0, 'partial' => 0, 'neutral' => 0],
            'recent_reports' => [],
            'compliance_stats' => ['excellent' => 0, 'good' => 0, 'fair' => 0, 'poor' => 0, 'total_domains' => 0],
        ];
    }
}
