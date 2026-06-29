<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/DMARCStatisticsRepository.php

namespace VEximweb\Plugin\DMARC\Repositories;

use VEximweb\Plugin\DMARC\Models\DMARCStatistics;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCStatisticsRepositoryInterface;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class DMARCStatisticsRepository implements DMARCStatisticsRepositoryInterface
{
    protected $model;
    
    public function __construct(DMARCStatistics $model)
    {
        $this->model = $model;
    }
    
    public function updateOrCreate(array $conditions, array $data): DMARCStatistics
    {
        return $this->model->updateOrCreate($conditions, $data);
    }
    
    public function getByDomain(string $domain, int $days = 30): Collection
    {
        return $this->model
            ->where('domain', $domain)
            ->where('date', '>=', Carbon::now()->subDays($days))
            ->orderBy('date', 'asc')
            ->get();
    }
    
    public function getDomainSummary(string $domain): array
    {
        $stats = $this->model
            ->where('domain', $domain)
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();
        
        if ($stats->isEmpty()) {
            return [
                'domain' => $domain,
                'total_messages' => 0,
                'avg_compliance_rate' => 0,
                'current_compliance_rate' => 0,
                'compliance_level' => 'unknown',
                'trend' => 'stable',
                'total_reports' => 0,
            ];
        }
        
        $current = $stats->first();
        $previous = $stats->skip(1)->first();
        
        return [
            'domain' => $domain,
            'total_messages' => $stats->sum('total_messages'),
            'avg_compliance_rate' => round($stats->avg('compliance_rate'), 2),
            'current_compliance_rate' => $current->compliance_rate,
            'compliance_level' => $current->compliance_level,
            'trend' => $this->calculateTrend($current->compliance_rate, $previous?->compliance_rate),
            'total_reports' => $stats->count(),
            'top_failing_ips' => $current->top_failing_ips,
            'failure_reasons' => $current->failure_reasons ?? [],
        ];
    }
    
    public function getGlobalStats(): array
    {
        $latest = $this->model
            ->select('domain', 'compliance_rate', 'total_messages', 'date')
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                    ->from('vw_dmarc_statistics')
                    ->groupBy('domain');
            })
            ->get();
        
        return [
            'total_domains' => $latest->count(),
            'avg_compliance_rate' => round($latest->avg('compliance_rate'), 2),
            'total_messages' => $latest->sum('total_messages'),
            'domains_with_excellent' => $latest->where('compliance_rate', '>=', 90)->count(),
            'domains_with_good' => $latest->whereBetween('compliance_rate', [70, 90])->count(),
            'domains_with_fair' => $latest->whereBetween('compliance_rate', [50, 70])->count(),
            'domains_with_poor' => $latest->where('compliance_rate', '<', 50)->count(),
        ];
    }
    
    public function getDomainsWithLowCompliance(float $threshold = 70): Collection
    {
        return $this->model
            ->select('domain', 'compliance_rate', 'date')
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                    ->from('vw_dmarc_statistics')
                    ->groupBy('domain');
            })
            ->where('compliance_rate', '<', $threshold)
            ->orderBy('compliance_rate', 'asc')
            ->get();
    }
    
    public function getDailyTrend(string $domain, int $days = 30): Collection
    {
        return $this->model
            ->where('domain', $domain)
            ->where('date', '>=', Carbon::now()->subDays($days))
            ->orderBy('date', 'asc')
            ->get(['date', 'compliance_rate', 'total_messages', 'pass_count', 'quarantine_count', 'reject_count']);
    }
    
    protected function calculateTrend(?float $current, ?float $previous): string
    {
        if ($current === null || $previous === null) {
            return 'stable';
        }
        
        $diff = $current - $previous;
        
        if (abs($diff) < 1) {
            return 'stable';
        }
        
        return $diff > 0 ? 'improving' : 'declining';
    }
}
