<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/Interfaces/DMARCPolicyRepositoryInterface.php

namespace VEximweb\Plugin\DMARC\Repositories\Interfaces;

use VEximweb\Plugin\DMARC\Models\DMARCPolicy;
use Illuminate\Support\Collection;

interface DMARCPolicyRepositoryInterface
{
    public function create(array $data): DMARCPolicy;
    public function findByReportId(int $reportId): ?DMARCPolicy;
    public function getByDomain(string $domain): Collection;
    public function getPoliciesWithLevel(string $level): Collection;
    public function updateOrCreate(array $conditions, array $data): DMARCPolicy;
}
