<?php
// plugins/vexim-web-plugin-dmarc/src/Repositories/Interfaces/DMARCDKIMResultRepositoryInterface.php

namespace VEximweb\Plugin\DMARC\Repositories\Interfaces;

use VEximweb\Plugin\DMARC\Models\DMARCdkimResult;
use Illuminate\Support\Collection;

interface DMARCDKIMResultRepositoryInterface
{
    public function create(array $data): DMARCdkimResult;
    public function getByRecordId(int $recordId): Collection;
    public function getByDomain(string $domain): Collection;
    public function getFailureStats(string $domain, int $days = 30): array;
}
