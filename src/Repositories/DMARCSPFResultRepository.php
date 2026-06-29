<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/DMARCSPFResultRepository.php

namespace VEximweb\Plugin\DMARC\Repositories;

use VEximweb\Plugin\DMARC\Models\DMARCSpfResult;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCSPFResultRepositoryInterface;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DMARCSPFResultRepository implements DMARCSPFResultRepositoryInterface
{
    protected $model;
    
    public function __construct(DMARCSpfResult $model)
    {
        $this->model = $model;
    }
    
    public function create(array $data): DMARCSpfResult
    {
        return $this->model->create($data);
    }
    
    public function getByRecordId(int $recordId): Collection
    {
        return $this->model
            ->where('record_id', $recordId)
            ->get();
    }
    
    public function getByDomain(string $domain): Collection
    {
        return $this->model
            ->where('domain', $domain)
            ->with('record.report')
            ->orderBy('created_at', 'desc')
            ->get();
    }
    
    public function getFailureStats(string $domain, int $days = 30): array
    {
        $results = $this->model
            ->where('domain', $domain)
            ->whereHas('record.report', function ($query) use ($days) {
                $query->where('date_begin', '>=', Carbon::now()->subDays($days));
            })
            ->select('result', DB::raw('COUNT(*) as count'))
            ->groupBy('result')
            ->get();
        
        $stats = [];
        foreach ($results as $result) {
            $stats[$result->result] = $result->count;
        }
        
        return $stats;
    }
}
