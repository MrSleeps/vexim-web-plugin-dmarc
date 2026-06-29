<?php
// plugins/vexim-web-plugin-dmarc/src/Models/DMARCStatistics.php

namespace VEximweb\Plugin\DMARC\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DMARCStatistics extends Model
{
    use HasFactory;
    
    protected $table = 'vw_dmarc_statistics';
    
    protected $fillable = [
        'domain',
        'date',
        'total_messages',
        'pass_count',
        'quarantine_count',
        'reject_count',
        'compliance_rate',
        'source_ip_summary',
        'failure_reasons',
    ];
    
    protected $casts = [
        'date' => 'date',
        'source_ip_summary' => 'array',
        'failure_reasons' => 'array',
    ];
    
    public function getComplianceLevelAttribute(): string
    {
        if ($this->compliance_rate >= 90) {
            return 'excellent';
        } elseif ($this->compliance_rate >= 70) {
            return 'good';
        } elseif ($this->compliance_rate >= 50) {
            return 'fair';
        }
        return 'poor';
    }
    
    public function getTopFailingIpsAttribute(): array
    {
        if (!$this->source_ip_summary) {
            return [];
        }
        
        arsort($this->source_ip_summary);
        return array_slice($this->source_ip_summary, 0, 10, true);
    }
}