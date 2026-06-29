<?php
// plugins/vexim-web-plugin-dmarc/src/Models/DMARCRecord.php

namespace VEximweb\Plugin\DMARC\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DMARCRecord extends Model
{
    use HasFactory;
    
    protected $table = 'vw_dmarc_records';
    
    protected $fillable = [
        'report_id',
        'source_ip',
        'count',
        'disposition',
        'dkim_result',
        'spf_result',
        'record_date',
    ];
    
    public function report(): BelongsTo
    {
        return $this->belongsTo(DMARCReport::class, 'report_id');
    }
    
    public function dkimResults(): HasMany
    {
        return $this->hasMany(DMARCdkimResult::class, 'record_id');
    }
    
    public function spfResults(): HasMany
    {
        return $this->hasMany(DMARCSpfResult::class, 'record_id');
    }
    
    public function getIsCompliantAttribute(): bool
    {
        return $this->disposition === 'none' || 
               ($this->dkim_result === 'pass' && $this->spf_result === 'pass');
    }
    
    public function getAuthenticationStatusAttribute(): string
    {
        if ($this->dkim_result === 'pass' && $this->spf_result === 'pass') {
            return 'pass';
        } elseif ($this->dkim_result === 'fail' && $this->spf_result === 'fail') {
            return 'fail_both';
        } elseif ($this->dkim_result === 'fail') {
            return 'fail_dkim';
        } elseif ($this->spf_result === 'fail') {
            return 'fail_spf';
        }
        return 'partial';
    }
}