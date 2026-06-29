<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. DMARC Reports (main table)
        Schema::create('vw_dmarc_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('org_name');
            $table->string('email');
            $table->string('domain');
            $table->timestamp('date_begin');
            $table->timestamp('date_end');
            $table->json('raw_data')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['domain', 'date_begin']);
            $table->index('org_name');
            $table->index('report_id');
            $table->index('created_at');
        });

        // 2. DMARC Policies
        Schema::create('vw_dmarc_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')
                  ->constrained('vw_dmarc_reports')
                  ->cascadeOnDelete();
            $table->string('domain');
            $table->enum('adkim', ['r', 's']);
            $table->enum('aspf', ['r', 's']);
            $table->enum('p', ['none', 'quarantine', 'reject']);
            $table->enum('sp', ['none', 'quarantine', 'reject'])->nullable();
            $table->integer('pct')->default(100);
            $table->enum('fo', ['0', '1', 'd', 's'])->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('domain');
            $table->index('report_id');
            $table->index(['domain', 'p']);
        });

        // 3. DMARC Records (individual authentication results)
        Schema::create('vw_dmarc_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')
                  ->constrained('vw_dmarc_reports')
                  ->cascadeOnDelete();
            $table->string('source_ip');
            $table->integer('count')->default(0);
            $table->enum('disposition', ['none', 'quarantine', 'reject']);
            $table->enum('dkim_result', ['pass', 'fail', 'neutral', 'temperror', 'permerror', 'none']);
            $table->enum('spf_result', ['pass', 'fail', 'softfail', 'neutral', 'temperror', 'permerror', 'none']);
            $table->timestamp('record_date')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['source_ip', 'disposition']);
            $table->index('record_date');
            $table->index('report_id');
            $table->index(['disposition', 'record_date']);
            $table->index(['dkim_result', 'spf_result']);
        });

        // 4. DKIM Results (per record)
        Schema::create('vw_dmarc_dkim_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')
                  ->constrained('vw_dmarc_records')
                  ->cascadeOnDelete();
            $table->string('domain');
            $table->string('selector')->nullable();
            $table->enum('result', ['pass', 'fail', 'neutral', 'temperror', 'permerror', 'none']);
            $table->enum('alignment', ['r', 's'])->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['domain', 'result']);
            $table->index('record_id');
            $table->index('result');
            $table->index(['domain', 'selector']);
        });

        // 5. SPF Results (per record)
        Schema::create('vw_dmarc_spf_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')
                  ->constrained('vw_dmarc_records')
                  ->cascadeOnDelete();
            $table->string('domain');
            $table->string('scope')->nullable();
            $table->enum('result', ['pass', 'fail', 'softfail', 'neutral', 'temperror', 'permerror', 'none']);
            $table->enum('alignment', ['r', 's'])->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['domain', 'result']);
            $table->index('record_id');
            $table->index('result');
            $table->index('scope');
        });

        // 6. DMARC Report Statistics (aggregated data for quick dashboards)
        Schema::create('vw_dmarc_statistics', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->date('date');
            $table->integer('total_messages')->default(0);
            $table->integer('pass_count')->default(0);
            $table->integer('fail_count')->default(0);
            $table->integer('quarantine_count')->default(0);
            $table->integer('reject_count')->default(0);
            $table->float('compliance_rate')->default(0);
            $table->json('source_ip_summary')->nullable();
            $table->json('failure_reasons')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->unique(['domain', 'date']);
            $table->index(['domain', 'date']);
            $table->index('domain');
            $table->index('date');
            $table->index('compliance_rate');
        });
    }

    public function down()
    {
        Schema::dropIfExists('vw_dmarc_statistics');
        Schema::dropIfExists('vw_dmarc_spf_results');
        Schema::dropIfExists('vw_dmarc_dkim_results');
        Schema::dropIfExists('vw_dmarc_records');
        Schema::dropIfExists('vw_dmarc_policies');
        Schema::dropIfExists('vw_dmarc_reports');
    }
};