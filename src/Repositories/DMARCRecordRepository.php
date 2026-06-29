<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/DMARCSystem/src/Repositories/DMARCRecordRepository.php

namespace VEximweb\Plugin\DMARC\Repositories;

use VEximweb\Plugin\DMARC\Models\DMARCRecord;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCRecordRepositoryInterface;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DMARCRecordRepository implements DMARCRecordRepositoryInterface
{
    protected $model;
    
    public function __construct(DMARCRecord $model)
    {
        $this->model = $model;
    }
    
    public function create(array $data): DMARCRecord
    {
        return $this->model->create($data);
    }
    
    public function getByReportId(int $reportId): Collection
    {
        return $this->model
            ->where('report_id', $reportId)
            ->with(['dkimResults', 'spfResults'])
            ->get();
    }
    
    public function getByDomain(string $domain, int $limit = 100): Collection
    {
        return $this->model
            ->whereHas('report', function ($query) use ($domain) {
                $query->where('domain', $domain);
            })
            ->with(['report', 'dkimResults', 'spfResults'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
    
    public function getFailingRecords(string $domain, int $days = 30): Collection
    {
        return $this->model
            ->whereHas('report', function ($query) use ($domain) {
                $query->where('domain', $domain)
                    ->where('date_begin', '>=', Carbon::now()->subDays($days));
            })
            ->where(function ($query) {
                $query->where('dkim_result', '!=', 'pass')
                    ->orWhere('spf_result', '!=', 'pass');
            })
            ->with(['report', 'dkimResults', 'spfResults'])
            ->orderBy('created_at', 'desc')
            ->get();
    }
    
    public function getTopFailingIps(string $domain, int $days = 30, int $limit = 10): Collection
    {
        return $this->model
            ->whereHas('report', function ($query) use ($domain, $days) {
                $query->where('domain', $domain)
                    ->where('date_begin', '>=', Carbon::now()->subDays($days));
            })
            ->where(function ($query) {
                $query->where('dkim_result', '!=', 'pass')
                    ->orWhere('spf_result', '!=', 'pass');
            })
            ->select('source_ip', DB::raw('SUM(count) as total_failures'))
            ->groupBy('source_ip')
            ->orderBy('total_failures', 'desc')
            ->limit($limit)
            ->get();
    }
    
    public function getAuthenticationStats(string $domain, int $days = 30): array
    {
        $records = $this->model
            ->whereHas('report', function ($query) use ($domain, $days) {
                $query->where('domain', $domain)
                    ->where('date_begin', '>=', Carbon::now()->subDays($days));
            })
            ->get();
        
        return [
            'total_messages' => $records->sum('count'),
            'pass_count' => $records->filter(function ($r) {
                return $r->is_compliant;
            })->sum('count'),
            'dkim_fail_count' => $records->filter(function ($r) {
                return $r->dkim_result !== 'pass';
            })->sum('count'),
            'spf_fail_count' => $records->filter(function ($r) {
                return $r->spf_result !== 'pass';
            })->sum('count'),
            'disposition_stats' => [
                'none' => $records->where('disposition', 'none')->sum('count'),
                'quarantine' => $records->where('disposition', 'quarantine')->sum('count'),
                'reject' => $records->where('disposition', 'reject')->sum('count'),
            ],
        ];
    }
}
