<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/DMARCPolicyRepository.php

namespace VEximweb\Plugin\DMARC\Repositories;

use VEximweb\Plugin\DMARC\Models\DMARCPolicy;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCPolicyRepositoryInterface;
use Illuminate\Support\Collection;

class DMARCPolicyRepository implements DMARCPolicyRepositoryInterface
{
    protected $model;
    
    public function __construct(DMARCPolicy $model)
    {
        $this->model = $model;
    }
    
    public function create(array $data): DMARCPolicy
    {
        return $this->model->create($data);
    }
    
    public function findByReportId(int $reportId): ?DMARCPolicy
    {
        return $this->model->where('report_id', $reportId)->first();
    }
    
    public function getByDomain(string $domain): Collection
    {
        return $this->model
            ->where('domain', $domain)
            ->with('report')
            ->orderBy('created_at', 'desc')
            ->get();
    }
    
    public function getPoliciesWithLevel(string $level): Collection
    {
        return $this->model
            ->get()
            ->filter(function ($policy) use ($level) {
                return $policy->policy_level === $level;
            });
    }
    
    public function updateOrCreate(array $conditions, array $data): DMARCPolicy
    {
        return $this->model->updateOrCreate($conditions, $data);
    }
}
