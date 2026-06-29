<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/Interfaces/DMARCRecordRepositoryInterface.php

namespace VEximweb\Plugin\DMARC\Repositories\Interfaces;

use VEximweb\Plugin\DMARC\Models\DMARCRecord;
use Illuminate\Support\Collection;

interface DMARCRecordRepositoryInterface
{
    public function create(array $data): DMARCRecord;
    
    public function getByReportId(int $reportId): Collection;
    
    public function getByDomain(string $domain, int $limit = 100): Collection;
    
    public function getFailingRecords(string $domain, int $days = 30): Collection;
    
    public function getTopFailingIps(string $domain, int $days = 30, int $limit = 10): Collection;
    
    public function getAuthenticationStats(string $domain, int $days = 30): array;
}
