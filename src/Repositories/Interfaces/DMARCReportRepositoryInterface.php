<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/Interfaces/DMARCReportRepositoryInterface.php

namespace VEximweb\Plugin\DMARC\Repositories\Interfaces;

use VEximweb\Plugin\DMARC\Models\DMARCReport;
use Illuminate\Support\Collection;

interface DMARCReportRepositoryInterface
{
    public function create(array $data): DMARCReport;
    
    public function findByReportId(string $reportId): ?DMARCReport;
    
    public function getByDomain(string $domain, int $limit = 100): Collection;
    
    public function getDateRange(string $domain, $startDate, $endDate): Collection;
    
    public function getLatestReports(int $limit = 10): Collection;
    
    public function getComplianceTrend(string $domain, int $days = 30): Collection;
    
    public function findOrCreate(array $data): DMARCReport;
}
