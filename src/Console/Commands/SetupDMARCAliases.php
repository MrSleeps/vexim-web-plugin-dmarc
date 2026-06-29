<?php
namespace VEximweb\Plugin\DMARC\Console\Commands;

use Illuminate\Console\Command;
use VEximweb\Core\Data\Models\Domain;
use VEximweb\Core\Data\Models\EximUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SetupDMARCAliases extends Command
{
    protected $signature = 'vw:setup-dmarc-aliases 
                            {--dry-run : Run without making changes}
                            {--domain= : Only process a specific domain}';
    
    protected $description = 'Setup DMARC aliases for all domains';

    protected $dmarcEmail;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Setting up DMARC aliases...');
        
        // Get DMARC email from config
        $this->dmarcEmail = config('dmarc.mailboxes.default.dmarc_email');
        
        if (empty($this->dmarcEmail)) {
            $this->error('DMARC email not configured in config/dmarc.php');
            $this->error('   Please set DMARC_IMAP_EMAIL in your .env file');
            return 1;
        }

        $this->info("DMARC email: {$this->dmarcEmail}");
        
        $isDryRun = $this->option('dry-run');
        $specificDomain = $this->option('domain');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Build domain query
        $query = Domain::where('enabled', 1);
        
        if ($specificDomain) {
            $query->where('domain', $specificDomain);
        }

        $domains = $query->get();

        if ($domains->isEmpty()) {
            $this->error('No enabled domains found');
            return 1;
        }

        $this->info("Found " . $domains->count() . " domain(s) to process");
        
        $stats = [
            'total' => $domains->count(),
            'already_exists' => 0,
            'created' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $bar = $this->output->createProgressBar($domains->count());
        $bar->start();

        foreach ($domains as $domain) {
            $this->processDomain($domain, $stats, $isDryRun, $bar);
        }

        $bar->finish();
        $this->newLine(2);

        // Display summary
        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total domains processed', $stats['total']],
                ['Already had DMARC alias', $stats['already_exists']],
                ['New aliases created', $stats['created']],
                ['Skipped (errors)', $stats['skipped']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($isDryRun) {
            $this->warn('This was a DRY RUN. No changes were made.');
            $this->warn('   Remove --dry-run to actually create the aliases.');
        }

        return 0;
    }

    /**
     * Process a single domain
     */
    protected function processDomain($domain, &$stats, $isDryRun, $bar): void
    {
        $bar->advance();

        try {
            $dmarcLocalpart = 'dmarc';
            $fullEmail = $dmarcLocalpart . '@' . $domain->domain;

            // Check if DMARC alias already exists
            $existingUser = EximUser::where('domain_id', $domain->domain_id)
                ->where('localpart', $dmarcLocalpart)
                ->where('type', 'alias')
                ->first();

            if ($existingUser) {
                $this->line("{$fullEmail} - Already exists (ID: {$existingUser->user_id})");
                $stats['already_exists']++;
                return;
            }

            // Check if there's a local user with the same localpart (conflict)
            $existingLocal = EximUser::where('domain_id', $domain->domain_id)
                ->where('localpart', $dmarcLocalpart)
                ->where('type', 'local')
                ->first();

            if ($existingLocal) {
                $this->warn("{$fullEmail} - Local user exists, skipping alias creation");
                $stats['skipped']++;
                return;
            }

            // Create the alias
            if (!$isDryRun) {
                $user = new EximUser();
                $user->domain_id = $domain->domain_id;
                $user->localpart = $dmarcLocalpart;
                $user->username = $fullEmail;
                $user->crypt = null; // No password for aliases
                $user->uid = 65534; // Default uid
                $user->gid = 65534; // Default gid
                $user->smtp = $this->dmarcEmail; // Store the DMARC email for SMTP
                $user->pop = $this->dmarcEmail; // Store the DMARC email for POP
                $user->type = 'alias';
                $user->admin = 0;
                $user->enabled = 1;
                $user->on_forward = 0;
                $user->forward = $this->dmarcEmail; // Forward to the DMARC email
                $user->created_at = now();
                $user->updated_at = now();
                $user->save();

                $this->line("{$fullEmail} - Created alias -> {$this->dmarcEmail}");
                $stats['created']++;
            } else {
                $this->line("{$fullEmail} - Would create alias -> {$this->dmarcEmail} (DRY RUN)");
                $stats['created']++;
            }

        } catch (\Exception $e) {
            $this->error("   Error processing {$domain->domain}: " . $e->getMessage());
            Log::error('DMARC alias setup failed', [
                'domain' => $domain->domain,
                'domain_id' => $domain->domain_id,
                'error' => $e->getMessage()
            ]);
            $stats['errors']++;
        }
    }
}