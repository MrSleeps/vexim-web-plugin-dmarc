<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/DMARCReportRepository.php

namespace VEximweb\Plugin\DMARC\Repositories;

use VEximweb\Plugin\DMARC\Models\DMARCReport;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCReportRepositoryInterface;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class DMARCReportRepository implements DMARCReportRepositoryInterface
{
    protected $model;
    
    public function __construct(DMARCReport $model)
    {
        $this->model = $model;
    }
    
    public function create(array $data): DMARCReport
    {
        return $this->model->create($data);
    }
    
    public function findByReportId(string $reportId): ?DMARCReport
    {
        return $this->model->where('report_id', $reportId)->first();
    }
    
    public function getByDomain(string $domain, int $limit = 100): Collection
    {
        return $this->model
            ->forDomain($domain)
            ->with(['policy', 'records'])
            ->orderBy('date_begin', 'desc')
            ->limit($limit)
            ->get();
    }
    
    public function getDateRange(string $domain, $startDate, $endDate): Collection
    {
        return $this->model
            ->forDomain($domain)
            ->dateRange($startDate, $endDate)
            ->with(['policy', 'records'])
            ->orderBy('date_begin', 'asc')
            ->get();
    }
    
    public function getLatestReports(int $limit = 10): Collection
    {
        return $this->model
            ->with(['policy', 'records'])
            ->orderBy('date_begin', 'desc')
            ->limit($limit)
            ->get();
    }
    
    public function getComplianceTrend(string $domain, int $days = 30): Collection
    {
        return $this->model
            ->forDomain($domain)
            ->where('date_begin', '>=', Carbon::now()->subDays($days))
            ->orderBy('date_begin', 'asc')
            ->get()
            ->map(function ($report) {
                return [
                    'date' => $report->date_begin->toDateString(),
                    'compliance_rate' => $report->compliance_rate,
                    'total_messages' => $report->records->sum('count'),
                ];
            });
    }
    
    public function findOrCreate(array $data): DMARCReport
    {
        $existing = $this->findByReportId($data['report_id']);
        
        if ($existing) {
            return $existing;
        }
        
        return $this->create($data);
    }
}
