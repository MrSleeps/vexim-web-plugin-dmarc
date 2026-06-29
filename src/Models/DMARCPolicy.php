<?php
// plugins/vexim-web-plugin-dmarc/src/Models/DMARCPolicy.php

namespace VEximweb\Plugin\DMARC\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DMARCPolicy extends Model
{
    use HasFactory;
    
    protected $table = 'vw_dmarc_policies';
    
    protected $fillable = [
        'report_id',
        'domain',
        'adkim',
        'aspf',
        'p',
        'sp',
        'pct',
        'fo',
    ];
    
    public function report(): BelongsTo
    {
        return $this->belongsTo(DMARCReport::class, 'report_id');
    }
    
    public function getPolicyTextAttribute(): string
    {
        $parts = [];
        $parts[] = "v=DMARC1";
        $parts[] = "p={$this->p}";
        if ($this->sp) $parts[] = "sp={$this->sp}";
        $parts[] = "pct={$this->pct}";
        $parts[] = "adkim={$this->adkim}";
        $parts[] = "aspf={$this->aspf}";
        
        return implode('; ', $parts);
    }
    
    public function getPolicyLevelAttribute(): string
    {
        $levels = [
            'none' => 0,
            'quarantine' => 1,
            'reject' => 2,
        ];
        
        $pLevel = $levels[$this->p] ?? 0;
        $spLevel = $this->sp ? ($levels[$this->sp] ?? 0) : $pLevel;
        
        return max($pLevel, $spLevel) >= 2 ? 'strict' : 
               (max($pLevel, $spLevel) >= 1 ? 'moderate' : 'lenient');
    }
}