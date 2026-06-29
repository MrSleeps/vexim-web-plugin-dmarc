<?php
// plugins/vexim-web-plugin-dmarc/src/Models/DMARCReport.php

namespace VEximweb\Plugin\DMARC\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DMARCReport extends Model
{
    use HasFactory;
    
    protected $table = 'vw_dmarc_reports';
    
    protected $fillable = [
        'report_id',
        'org_name',
        'email',
        'domain',
        'date_begin',
        'date_end',
        'raw_data',
    ];
    
    protected $casts = [
        'date_begin' => 'datetime',
        'date_end' => 'datetime',
    ];
    
    public function policy(): HasOne
    {
        return $this->hasOne(DMARCPolicy::class, 'report_id');
    }
    
    public function records(): HasMany
    {
        return $this->hasMany(DMARCRecord::class, 'report_id');
    }
    
    public function getComplianceRateAttribute(): float
    {
        $total = $this->records->sum('count');
        $passes = $this->records->filter(function ($record) {
            return $record->disposition === 'none' || 
                   ($record->dkim_result === 'pass' && $record->spf_result === 'pass');
        })->sum('count');
        
        return $total > 0 ? round(($passes / $total) * 100, 2) : 0;
    }
    
    public function getPolicyTextAttribute(): string
    {
        return $this->policy ? $this->policy->policy_text : 'No policy found';
    }
    
    public function scopeForDomain($query, $domain)
    {
        return $query->where('domain', $domain);
    }
    
    public function scopeDateRange($query, $start, $end)
    {
        return $query->whereBetween('date_begin', [$start, $end]);
    }
}