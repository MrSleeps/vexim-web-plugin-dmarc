<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/Interfaces/DMARCSPFResultRepositoryInterface.php

namespace VEximweb\Plugin\DMARC\Repositories\Interfaces;

use VEximweb\Plugin\DMARC\Models\DMARCSpfResult;
use Illuminate\Support\Collection;

interface DMARCSPFResultRepositoryInterface
{
    public function create(array $data): DMARCSpfResult;
    public function getByRecordId(int $recordId): Collection;
    public function getByDomain(string $domain): Collection;
    public function getFailureStats(string $domain, int $days = 30): array;
}
