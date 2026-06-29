<?php
// plugins/vexim-web-plugin-dmarc/src/Models/DMARCdkimResult.php

namespace VEximweb\Plugin\DMARC\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DMARCdkimResult extends Model
{
    use HasFactory;
    
    protected $table = 'vw_dmarc_dkim_results';
    
    protected $fillable = [
        'record_id',
        'domain',
        'selector',
        'result',
        'alignment',
    ];
    
    public function record(): BelongsTo
    {
        return $this->belongsTo(DMARCRecord::class, 'record_id');
    }
    
    public function getIsPassingAttribute(): bool
    {
        return $this->result === 'pass';
    }
}