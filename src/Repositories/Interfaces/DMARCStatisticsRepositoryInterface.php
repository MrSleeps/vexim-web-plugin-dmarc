<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/Interfaces/DMARCStatisticsRepositoryInterface.php

namespace VEximweb\Plugin\DMARC\Repositories\Interfaces;

use VEximweb\Plugin\DMARC\Models\DMARCStatistics;
use Illuminate\Support\Collection;

interface DMARCStatisticsRepositoryInterface
{
    public function updateOrCreate(array $conditions, array $data): DMARCStatistics;
    
    public function getByDomain(string $domain, int $days = 30): Collection;
    
    public function getDomainSummary(string $domain): array;
    
    public function getGlobalStats(): array;
    
    public function getDomainsWithLowCompliance(float $threshold = 70): Collection;
    
    public function getDailyTrend(string $domain, int $days = 30): Collection;
}
