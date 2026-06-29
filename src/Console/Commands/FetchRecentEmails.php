<?php

namespace VEximweb\Plugin\DMARC\Console\Commands;

use Illuminate\Console\Command;
use DirectoryTree\ImapEngine\Mailbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCReportRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCPolicyRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCRecordRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCDKIMResultRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCSPFResultRepositoryInterface;
use VEximweb\Plugin\DMARC\Repositories\Interfaces\DMARCStatisticsRepositoryInterface;

class FetchRecentEmails extends Command
{
    protected $signature = 'vw:fetch-dmarc-emails 
                            {--mailbox=default : The mailbox connection to use}
                            {--hours=1 : Number of hours to look back}
                            {--save-attachments : Save extracted attachments to storage}';
    
    protected $description = 'Fetch emails from DMARC reports inbox';

    // Directory to store extracted reports
    protected string $storagePath = 'dmarc-reports';

    protected DMARCReportRepositoryInterface $reportRepository;
    protected DMARCPolicyRepositoryInterface $policyRepository;
    protected DMARCRecordRepositoryInterface $recordRepository;
    protected DMARCDKIMResultRepositoryInterface $dkimResultRepository;
    protected DMARCSPFResultRepositoryInterface $spfResultRepository;
    protected DMARCStatisticsRepositoryInterface $statisticsRepository;

    public function __construct(
        DMARCReportRepositoryInterface $reportRepository,
        DMARCPolicyRepositoryInterface $policyRepository,
        DMARCRecordRepositoryInterface $recordRepository,
        DMARCDKIMResultRepositoryInterface $dkimResultRepository,
        DMARCSPFResultRepositoryInterface $spfResultRepository,
        DMARCStatisticsRepositoryInterface $statisticsRepository
    ) {
        parent::__construct();
        
        $this->reportRepository = $reportRepository;
        $this->policyRepository = $policyRepository;
        $this->recordRepository = $recordRepository;
        $this->dkimResultRepository = $dkimResultRepository;
        $this->spfResultRepository = $spfResultRepository;
        $this->statisticsRepository = $statisticsRepository;
    }

    public function handle()
    {
        $mailboxName = $this->option('mailbox');
        $hoursBack = (int) $this->option('hours');
        $saveAttachments = $this->option('save-attachments');
        
        $this->info("Connecting to mailbox: {$mailboxName}");
        $this->info("Looking back: {$hoursBack} hour(s)");
        
        try {
            $config = $this->getMailboxConfig($mailboxName);
            
            if (!$config) {
                $this->error("Mailbox '{$mailboxName}' not found in configuration.");
                return 1;
            }
            
            // Create the mailbox with configuration array
            $mailbox = new Mailbox($config);
            
            // Connect to the mailbox
            $mailbox->connect();
            
            $this->info("Connected successfully!");
            
            // Get the inbox folder
            $inbox = $mailbox->inbox();
            
            // Get the total number of messages
            $status = $inbox->status();
            $totalMessages = $status['MESSAGES'] ?? 0;
            $this->info("Total messages in inbox: {$totalMessages}");
            
            if ($totalMessages === 0) {
                $this->info("No messages found.");
                $mailbox->disconnect();
                return 0;
            }
            
            // Calculate how many days to fetch based on hours
            $daysToFetch = max(1, ceil($hoursBack / 24));
            $dateThreshold = Carbon::now()->subDays($daysToFetch);
            
            $this->info("Fetching messages since: " . $dateThreshold->toDateString());
            
            // Build the search query with headers, flags, and body
            $query = $inbox->messages()
                ->since($dateThreshold)
                ->withHeaders()
                ->withFlags()
                ->withBody()
                ->withBodyStructure();
            
            // Get messages
            $messages = $query->get();
            
            $this->info("Messages found since " . $dateThreshold->toDateString() . ": " . $messages->count());
            
            // Filter messages received in the specified time range
            $timeThreshold = Carbon::now()->subHours($hoursBack);
            $recentMessages = $messages->filter(function ($message) use ($timeThreshold) {
                $messageDate = $message->date();
                return $messageDate && $messageDate->greaterThanOrEqualTo($timeThreshold);
            });
            
            // Process the messages
            if ($recentMessages->isEmpty()) {
                $this->info("No emails found in the last {$hoursBack} hour(s).");
                $mailbox->disconnect();
                return 0;
            }
            
            $this->info("Found " . $recentMessages->count() . " emails in the last {$hoursBack} hour(s):");
            
            $rows = $recentMessages->map(function ($message) {
                $from = $message->from();
                return [
                    $message->date()?->toDateTimeString() ?? 'N/A',
                    $from ? $from->email() : 'Unknown',
                    $message->subject() ?? 'No Subject',
                    $message->uid(),
                    $message->isSeen() ? 'Yes' : 'No',
                ];
            })->toArray();
            
            $this->table(
                ['Date', 'From', 'Subject', 'UID', 'Seen'],
                $rows
            );
            
            // Process DMARC reports
            foreach ($recentMessages as $message) {
                $this->processDMARCReport($message, $saveAttachments);
            }
            
            // Disconnect
            $mailbox->disconnect();
            
            return 0;
            
        } catch (\DirectoryTree\ImapEngine\Exceptions\ImapConnectionException $e) {
            $this->error("Connection failed: " . $e->getMessage());
            Log::error('DMARC IMAP Connection Error', [
                'mailbox' => $mailboxName,
                'error' => $e->getMessage()
            ]);
            return 1;
        } catch (\DirectoryTree\ImapEngine\Exceptions\ImapCommandException $e) {
            $this->error("Authentication failed: " . $e->getMessage());
            Log::error('DMARC IMAP Authentication Error', [
                'mailbox' => $mailboxName,
                'error' => $e->getMessage()
            ]);
            return 1;
        } catch (\Exception $e) {
            $this->error("Failed to fetch emails: " . $e->getMessage());
            Log::error('DMARC IMAP Error', [
                'mailbox' => $mailboxName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Get mailbox configuration
     */
    protected function getMailboxConfig(string $mailboxName): ?array
    {
        $config = config('dmarc.mailboxes.' . $mailboxName);
        
        if (!$config) {
            $defaultMailbox = config('dmarc.default', 'default');
            if ($mailboxName !== $defaultMailbox) {
                $this->info("Falling back to default mailbox: {$defaultMailbox}");
                return $this->getMailboxConfig($defaultMailbox);
            }
            return null;
        }
        
        return $config;
    }
    
    /**
     * Process a DMARC report email - Enhanced for Google reports
     */
    protected function processDMARCReport($message, bool $saveAttachments = false): void
    {
        $from = $message->from();
        $fromEmail = $from ? $from->email() : 'Unknown';
        $subject = $message->subject() ?? '';
        $messageDate = $message->date();
        
        // Look for DMARC report indicators
        if (str_contains(strtolower($subject), 'dmarc') || 
            str_contains(strtolower($subject), 'report') ||
            str_contains(strtolower($fromEmail), 'dmarc') ||
            str_contains(strtolower($fromEmail), 'google')) {
            
            $this->line("\nProcessing DMARC report from: {$fromEmail}");
            $this->line("   Date: " . ($messageDate ? $messageDate->toDateTimeString() : 'Unknown'));
            $this->line("   Subject: {$subject}");
            
            // Try multiple methods to get attachments
            $attachmentParts = $this->findAllAttachments($message);
            
            if (empty($attachmentParts)) {
                $this->line("No attachments found through standard detection.");
                $this->line("   Attempting to find inline/gzip content parts...");
                
                // Fallback: look for any gzip or xml content in body parts
                $attachmentParts = $this->findPotentialDMARCParts($message);
            }
            
            if (empty($attachmentParts)) {
                $this->line("No DMARC report attachments found.");
                return;
            }
            
            $this->line("Found " . count($attachmentParts) . " attachment(s):");
            
            foreach ($attachmentParts as $partInfo) {
                $filename = $partInfo['filename'];
                $contentType = $partInfo['contentType'];
                $partNumber = $partInfo['partNumber'];
                $encoding = $partInfo['encoding'] ?? 'unknown';
                $isInline = $partInfo['isInline'] ?? false;
                
                $this->line("{$filename} ({$contentType}, encoding: {$encoding}, inline: " . ($isInline ? 'yes' : 'no') . ")");
                
                try {
                    // Fetch the attachment content
                    $content = $message->bodyPart($partNumber);
                    $size = strlen($content);
                    
                    $this->line("      Raw size: " . $this->formatBytes($size));
                    
                    if ($size === 0) {
                        $this->line("Empty content");
                        continue;
                    }
                    
                    // Handle base64 encoding
                    if (strtolower($encoding) === 'base64' || $this->looksLikeBase64($content)) {
                        $this->line("Decoding base64...");
                        $decoded = base64_decode($content, true);
                        if ($decoded !== false && $decoded !== '') {
                            $content = $decoded;
                            $this->line("Base64 decoded: " . strlen($content) . " bytes");
                        } else {
                            $this->line("Base64 decode failed");
                        }
                    }
                    
                    // Check magic bytes
                    $hex = bin2hex(substr($content, 0, 10));
                    $this->line("Magic bytes: " . $hex);
                    
                    // Detect if this is a gzip file (Google uses this)
                    if ($this->isGzipContent($content)) {
                        $this->line("Detected GZIP content");
                        
                        // Save raw gzip if requested
                        if ($saveAttachments) {
                            $this->saveRawAttachment($content, $filename, $fromEmail);
                        }
                        
                        // Extract the gzip content
                        $extractedContent = $this->extractGzipContent($content);
                        
                        if ($extractedContent) {
                            $this->line("Extracted " . strlen($extractedContent) . " bytes");
                            
                            if ($this->isXML($extractedContent)) {
                                $this->line("XML content detected");
                                $this->parseAndSaveDMARCXML($extractedContent, $fromEmail, $subject);
                                
                                if ($saveAttachments) {
                                    $this->saveExtractedReport($extractedContent, $filename, $fromEmail);
                                }
                            } else {
                                // Try to find XML within the extracted content
                                $xmlContent = $this->extractXMLFromContent($extractedContent);
                                if ($xmlContent) {
                                    $this->line("XML found within extracted content");
                                    $this->parseAndSaveDMARCXML($xmlContent, $fromEmail, $subject);
                                } else {
                                    $this->line("Extracted content is not XML (preview):");
                                    $preview = substr(trim($extractedContent), 0, 200);
                                    $this->line("      " . $preview . "...");
                                }
                            }
                        } else {
                            $this->line("Failed to extract gzip content");
                        }
                    } elseif ($this->isXMLByContent($content, $filename, $contentType)) {
                        $this->line("XML attachment found (" . strlen($content) . " bytes)");
                        $this->parseAndSaveDMARCXML($content, $fromEmail, $subject);
                        
                        if ($saveAttachments) {
                            $this->saveExtractedReport($content, $filename, $fromEmail);
                        }
                    } else {
                        // Try to find XML within the content
                        $xmlContent = $this->extractXMLFromContent($content);
                        if ($xmlContent) {
                            $this->line("XML found within content");
                            $this->parseAndSaveDMARCXML($xmlContent, $fromEmail, $subject);
                        } else {
                            $this->line("Skipping non-DMARC content");
                        }
                    }
                } catch (\Exception $e) {
                    $this->line("Error fetching part: " . $e->getMessage());
                    Log::error('Failed to fetch attachment part', [
                        'filename' => $filename,
                        'partNumber' => $partNumber,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }
    
    /**
     * Find all attachments including inline ones
     */
    protected function findAllAttachments($message): array
    {
        $parts = [];

        try {
            $structure = $message->bodyStructure();

            if (!$structure) {
                return $parts;
            }

            foreach ($structure->flatten() as $part) {
                // Check for any content disposition
                $isAttachment = false;
                $isInline = false;

                // Check if it's explicitly an attachment
                if (method_exists($part, 'isAttachment') && $part->isAttachment()) {
                    $isAttachment = true;
                }

                // Check for inline disposition
                if (method_exists($part, 'disposition')) {
                    $disposition = $part->disposition();

                    // Try different ways to get the string value
                    if ($disposition) {
                        $dispositionValue = null;

                        // Method 1: Try getValue()
                        if (method_exists($disposition, 'getValue')) {
                            $dispositionValue = $disposition->getValue();
                        }
                        // Method 2: Try getType() or similar
                        elseif (method_exists($disposition, 'getType')) {
                            $dispositionValue = $disposition->getType();
                        }
                        // Method 3: Try __toString if it exists (though it doesn't)
                        elseif (method_exists($disposition, '__toString')) {
                            $dispositionValue = (string) $disposition;
                        }
                        // Method 4: Try property access
                        elseif (isset($disposition->type)) {
                            $dispositionValue = $disposition->type;
                        }
                        // Method 5: Try to get the first element if it's an array
                        elseif (is_array($disposition) && count($disposition) > 0) {
                            $dispositionValue = reset($disposition);
                        }

                        if ($dispositionValue && strtolower($dispositionValue) === 'inline') {
                            $isInline = true;
                        }
                    }
                }

                // Check if it has a filename
                $filename = $part->filename() ?: $this->getFilenameFromParams($part);

                // Check content type for DMARC-related content
                $contentType = $part->contentType() ?: '';
                $isDmarcContent = $this->isDmarcContentType($contentType, $filename);

                // Include if: it's an attachment, inline with filename, or DMARC content
                if ($isAttachment || ($isInline && $filename) || $isDmarcContent) {
                    $parts[] = [
                        'partNumber' => $part->partNumber(),
                        'filename' => $filename ?: 'attachment.bin',
                        'contentType' => $contentType,
                        'size' => $part->size() ?? 0,
                        'encoding' => $part->encoding() ?? 'unknown',
                        'isInline' => $isInline,
                        'isAttachment' => $isAttachment,
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->line("Error scanning structure: " . $e->getMessage());
        }

        return $parts;
    }
    
    /**
     * Find potential DMARC parts when standard attachment detection fails
     */
    protected function findPotentialDMARCParts($message): array
    {
        $parts = [];

        try {
            $structure = $message->bodyStructure();

            if (!$structure) {
                return $parts;
            }

            foreach ($structure->flatten() as $part) {
                $contentType = $part->contentType() ?: '';
                $filename = $part->filename() ?: $this->getFilenameFromParams($part);

                // Check for gzip content
                if (str_contains($contentType, 'gzip') || 
                    str_contains($contentType, 'x-gzip') ||
                    (str_contains($contentType, 'octet-stream') && $filename && 
                     (str_ends_with(strtolower($filename), '.gz') || 
                      str_ends_with(strtolower($filename), '.gzip')))) {

                    $parts[] = [
                        'partNumber' => $part->partNumber(),
                        'filename' => $filename ?: 'report.gz',
                        'contentType' => $contentType,
                        'size' => $part->size() ?? 0,
                        'encoding' => $part->encoding() ?? 'unknown',
                        'isInline' => true,
                        'isAttachment' => false,
                    ];
                }

                // Check for XML content
                if (str_contains($contentType, 'xml') || 
                    str_contains($contentType, 'text/plain') ||
                    ($filename && str_ends_with(strtolower($filename), '.xml'))) {

                    $parts[] = [
                        'partNumber' => $part->partNumber(),
                        'filename' => $filename ?: 'report.xml',
                        'contentType' => $contentType,
                        'size' => $part->size() ?? 0,
                        'encoding' => $part->encoding() ?? 'unknown',
                        'isInline' => true,
                        'isAttachment' => false,
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->line("Error finding potential parts: " . $e->getMessage());
        }

        return $parts;
    }
    
    /**
     * Get filename from parameters if not directly available
     */
    protected function getFilenameFromParams($part): ?string
    {
        try {
            if (method_exists($part, 'parameters')) {
                $params = $part->parameters();
                if (isset($params['filename'])) {
                    return $params['filename'];
                }
                if (isset($params['name'])) {
                    return $params['name'];
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return null;
    }
    
    /**
     * Check if content is gzip
     */
    protected function isGzipContent(string $content): bool
    {
        return substr($content, 0, 2) === "\x1f\x8b";
    }
    
    /**
     * Check if content looks like base64
     */
    protected function looksLikeBase64(string $content): bool
    {
        $trimmed = trim($content);
        return preg_match('/^[A-Za-z0-9+\/]+=*$/', $trimmed) && 
               strlen($trimmed) % 4 === 0 && 
               strlen($trimmed) > 20;
    }
    
    /**
     * Check if content type is DMARC-related
     */
    protected function isDmarcContentType(string $contentType, ?string $filename): bool
    {
        $contentType = strtolower($contentType);
        $filename = strtolower($filename ?? '');
        
        return str_contains($contentType, 'gzip') ||
               str_contains($contentType, 'xml') ||
               str_contains($contentType, 'x-gzip') ||
               str_contains($contentType, 'octet-stream') ||
               str_ends_with($filename, '.gz') ||
               str_ends_with($filename, '.gzip') ||
               str_ends_with($filename, '.xml') ||
               str_contains($filename, 'dmarc');
    }
    
    /**
     * Extract XML from content (helps when XML is embedded in other text)
     */
    protected function extractXMLFromContent(string $content): ?string
    {
        // Try to find XML content within the text
        if (preg_match('/<\?xml.*<\/feedback>/s', $content, $matches)) {
            return $matches[0];
        }
        
        if (preg_match('/<feedback>.*<\/feedback>/s', $content, $matches)) {
            return $matches[0];
        }
        
        if (preg_match('/<report_metadata>.*<\/report_metadata>/s', $content, $matches)) {
            // Try to find the full feedback structure
            if (preg_match('/<feedback>.*<\/feedback>/s', $content, $fullMatch)) {
                return $fullMatch[0];
            }
            return $matches[0];
        }
        
        return null;
    }
    
    /**
     * Extract gzip content using multiple methods including the filter approach
     */
    protected function extractGzipContent(string $content): ?string
    {
        // Method 1: Try standard gzdecode
        if (function_exists('gzdecode')) {
            $result = @gzdecode($content);
            if ($result !== false && $result !== null && $result !== '') {
                return $result;
            }
        }
        
        // Method 2: Manual extraction (using the filter approach from dmarc-srg)
        $result = $this->manualGzipExtract($content);
        if ($result !== null) {
            return $result;
        }
        
        // Method 3: System gunzip
        $result = $this->gunzipDecompress($content);
        if ($result !== null) {
            return $result;
        }
        
        return null;
    }
    
    /**
     * Manual gzip extraction using the filter approach from dmarc-srg
     */
    protected function manualGzipExtract(string $content): ?string
    {
        try {
            if (substr($content, 0, 2) !== "\x1f\x8b") {
                return null;
            }
            
            $flags = ord($content[3]);
            $headerSize = 10;
            
            // Skip extra fields
            if ($flags & 0x04) {
                $extraLen = unpack('v', substr($content, $headerSize, 2))[1];
                $headerSize += 2 + $extraLen;
            }
            
            // Skip filename
            if ($flags & 0x08) {
                while ($headerSize < strlen($content) && $content[$headerSize] !== "\0") {
                    $headerSize++;
                }
                $headerSize++;
            }
            
            // Skip comment
            if ($flags & 0x10) {
                while ($headerSize < strlen($content) && $content[$headerSize] !== "\0") {
                    $headerSize++;
                }
                $headerSize++;
            }
            
            // Skip CRC16
            if ($flags & 0x02) {
                $headerSize += 2;
            }
            
            if ($headerSize >= strlen($content)) {
                return null;
            }
            
            // Get the compressed data (strip header and trailer)
            $compressedData = substr($content, $headerSize);
            
            // Remove trailer (last 8 bytes)
            if (strlen($compressedData) > 8) {
                $compressedData = substr($compressedData, 0, -8);
            }
            
            // Try to decompress
            if (function_exists('gzinflate')) {
                $result = @gzinflate($compressedData);
                if ($result !== false && $result !== null && $result !== '') {
                    return $result;
                }
            }
            
            if (function_exists('gzuncompress')) {
                $result = @gzuncompress($compressedData);
                if ($result !== false && $result !== null && $result !== '') {
                    return $result;
                }
            }
            
            if (function_exists('zlib_decode')) {
                $result = @zlib_decode($compressedData);
                if ($result !== false && $result !== null && $result !== '') {
                    return $result;
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Manual gzip extraction failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    /**
     * System gunzip decompression
     */
    protected function gunzipDecompress(string $content): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'dmarc_');
        $outputFile = tempnam(sys_get_temp_dir(), 'dmarc_out_');
        
        try {
            file_put_contents($tempFile, $content);
            
            $command = "gunzip -c '{$tempFile}' 2>/dev/null > '{$outputFile}'";
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputFile) && filesize($outputFile) > 0) {
                $result = file_get_contents($outputFile);
                unlink($tempFile);
                unlink($outputFile);
                return $result;
            }
            
            unlink($tempFile);
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
            return null;
            
        } catch (\Exception $e) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
            return null;
        }
    }
    
    /**
     * Extract content from ZIP file
     */
    protected function extractZipContent(string $content): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'dmarc_zip_');
        $extractDir = sys_get_temp_dir() . '/dmarc_extract_' . uniqid();
        
        try {
            file_put_contents($tempFile, $content);
            
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === true) {
                mkdir($extractDir, 0777, true);
                $zip->extractTo($extractDir);
                $zip->close();
                
                $files = glob($extractDir . '/*.xml');
                if (!empty($files)) {
                    $xmlContent = file_get_contents($files[0]);
                    unlink($tempFile);
                    array_map('unlink', glob($extractDir . '/*'));
                    rmdir($extractDir);
                    return $xmlContent;
                }
            }
            
            unlink($tempFile);
            if (is_dir($extractDir)) {
                array_map('unlink', glob($extractDir . '/*'));
                rmdir($extractDir);
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to extract ZIP', ['error' => $e->getMessage()]);
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            if (is_dir($extractDir)) {
                array_map('unlink', glob($extractDir . '/*'));
                rmdir($extractDir);
            }
            return null;
        }
    }
    
    /**
     * Check if content is XML
     */
    protected function isXML(string $content): bool
    {
        return str_contains(trim($content), '<?xml') || 
               str_contains(trim($content), '<feedback>') ||
               str_contains(trim($content), '<report_metadata>');
    }
    
    /**
     * Check if content is XML by content, filename, or content type
     */
    protected function isXMLByContent(string $content, string $filename, string $contentType): bool
    {
        if (str_contains($contentType, 'application/xml') || 
            str_contains($contentType, 'text/xml')) {
            return true;
        }
        
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'xml') {
            return true;
        }
        
        return $this->isXML($content);
    }
    
    /**
     * Parse and save DMARC XML to database using repositories
     */
    protected function parseAndSaveDMARCXML(string $xml, string $from, string $subject): ?array
    {
        try {
            // Clean XML
            if (preg_match('/<\?xml.*<\/feedback>/s', $xml, $matches)) {
                $xml = $matches[0];
            }
            
            $dom = new \DOMDocument();
            $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);
            $feedback = $dom->getElementsByTagName('feedback')->item(0);
            
            if (!$feedback) {
                return null;
            }
            
            // Parse report metadata
            $reportMetadata = $feedback->getElementsByTagName('report_metadata')->item(0);
            if (!$reportMetadata) {
                return null;
            }
            
            $reportId = $this->getElementValue($reportMetadata, 'report_id');
            $orgName = $this->getElementValue($reportMetadata, 'org_name');
            $email = $this->getElementValue($reportMetadata, 'email');
            
            $dateRange = $reportMetadata->getElementsByTagName('date_range')->item(0);
            $begin = $this->getElementValue($dateRange, 'begin');
            $end = $this->getElementValue($dateRange, 'end');
            
            // Parse policy
            $policyPublished = $feedback->getElementsByTagName('policy_published')->item(0);
            if (!$policyPublished) {
                return null;
            }
            
            $domain = $this->getElementValue($policyPublished, 'domain');
            $adkim = $this->getElementValue($policyPublished, 'adkim') ?: 'r';
            $aspf = $this->getElementValue($policyPublished, 'aspf') ?: 'r';
            $p = $this->getElementValue($policyPublished, 'p') ?: 'none';
            $sp = $this->getElementValue($policyPublished, 'sp');
            $pct = $this->getElementValue($policyPublished, 'pct') ?: 100;
            $fo = $this->getElementValue($policyPublished, 'fo');
            
            // Check if report already exists
            $existingReport = $this->reportRepository->findByReportId($reportId);
            if ($existingReport) {
                $this->line("Report already exists in database (ID: {$reportId})");
                return [
                    'report_id' => $reportId,
                    'domain' => $domain,
                    'record_count' => $existingReport->records->count(),
                ];
            }
            
            // Create report
            $report = $this->reportRepository->create([
                'report_id' => $reportId,
                'org_name' => $orgName,
                'email' => $email,
                'domain' => $domain,
                'date_begin' => $begin ? Carbon::createFromTimestamp($begin) : null,
                'date_end' => $end ? Carbon::createFromTimestamp($end) : null,
                'raw_data' => $xml,
            ]);
            
            // Create policy
            $this->policyRepository->create([
                'report_id' => $report->id,
                'domain' => $domain,
                'adkim' => $adkim,
                'aspf' => $aspf,
                'p' => $p,
                'sp' => $sp,
                'pct' => (int)$pct,
                'fo' => $fo,
            ]);
            
            // Parse records
            $recordCount = 0;
            $records = $feedback->getElementsByTagName('record');
            
            foreach ($records as $record) {
                $row = $record->getElementsByTagName('row')->item(0);
                if (!$row) continue;
                
                $sourceIp = $this->getElementValue($row, 'source_ip');
                $count = (int)($this->getElementValue($row, 'count') ?: 1);
                
                $policyEvaluated = $row->getElementsByTagName('policy_evaluated')->item(0);
                if ($policyEvaluated) {
                    $disposition = $this->getElementValue($policyEvaluated, 'disposition') ?: 'none';
                    $dkimResult = $this->getElementValue($policyEvaluated, 'dkim') ?: 'fail';
                    $spfResult = $this->getElementValue($policyEvaluated, 'spf') ?: 'fail';
                } else {
                    continue;
                }
                
                // Create record
                $recordModel = $this->recordRepository->create([
                    'report_id' => $report->id,
                    'source_ip' => $sourceIp,
                    'count' => $count,
                    'disposition' => $disposition,
                    'dkim_result' => $dkimResult,
                    'spf_result' => $spfResult,
                    'record_date' => $report->date_begin,
                ]);
                
                // Parse DKIM and SPF results
                $authResults = $record->getElementsByTagName('auth_results')->item(0);
                if ($authResults) {
                    // DKIM
                    $dkimResults = $authResults->getElementsByTagName('dkim');
                    foreach ($dkimResults as $dkim) {
                        $this->dkimResultRepository->create([
                            'record_id' => $recordModel->id,
                            'domain' => $this->getElementValue($dkim, 'domain') ?: $domain,
                            'selector' => $this->getElementValue($dkim, 'selector'),
                            'result' => $this->getElementValue($dkim, 'result') ?: 'fail',
                            'alignment' => $this->getElementValue($dkim, 'alignment'),
                        ]);
                    }
                    
                    // SPF
                    $spfResults = $authResults->getElementsByTagName('spf');
                    foreach ($spfResults as $spf) {
                        $this->spfResultRepository->create([
                            'record_id' => $recordModel->id,
                            'domain' => $this->getElementValue($spf, 'domain') ?: $domain,
                            'scope' => $this->getElementValue($spf, 'scope'),
                            'result' => $this->getElementValue($spf, 'result') ?: 'fail',
                            'alignment' => $this->getElementValue($spf, 'alignment'),
                        ]);
                    }
                }
                
                $recordCount++;
            }
            
            // Update statistics
            $this->updateStatistics($report);
            
            $this->line("Saved to database!");
            $this->line("Report ID: {$reportId}");
            $this->line("Domain: {$domain}");
            $this->line("Records: {$recordCount}");
            
            return [
                'report_id' => $reportId,
                'domain' => $domain,
                'record_count' => $recordCount,
            ];
            
        } catch (\Exception $e) {
            $this->line("Failed to save to database: " . $e->getMessage());
            Log::error('Failed to save DMARC report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    /**
     * Update aggregated statistics
     */
    protected function updateStatistics($report): void
    {
        try {
            $domain = $report->domain;
            $date = $report->date_begin->toDateString();
            
            $records = $this->recordRepository->getByReportId($report->id);
            $total = $records->sum('count');
            
            $passCount = $records->filter(function ($r) {
                return $r->disposition === 'none' || 
                       ($r->dkim_result === 'pass' && $r->spf_result === 'pass');
            })->sum('count');
            
            $quarantineCount = $records->where('disposition', 'quarantine')->sum('count');
            $rejectCount = $records->where('disposition', 'reject')->sum('count');
            
            $complianceRate = $total > 0 ? round(($passCount / $total) * 100, 2) : 0;
            
            // Get failure reasons            $failures = [];
            foreach ($records as $record) {
                if ($record->dkim_result !== 'pass' || $record->spf_result !== 'pass') {
                    $reasons = [];
                    if ($record->dkim_result !== 'pass') {
                        $reasons[] = 'dkim_' . $record->dkim_result;
                    }
                    if ($record->spf_result !== 'pass') {
                        $reasons[] = 'spf_' . $record->spf_result;
                    }
                    $key = implode('_', $reasons);
                    $failures[$key] = ($failures[$key] ?? 0) + $record->count;
                }
            }
            
            // Get IP summary
            $ipSummary = [];
            foreach ($records as $record) {
                $ipSummary[$record->source_ip] = ($ipSummary[$record->source_ip] ?? 0) + $record->count;
            }
            
            $this->statisticsRepository->updateOrCreate(
                [
                    'domain' => $domain,
                    'date' => $date,
                ],
                [
                    'total_messages' => $total,
                    'pass_count' => $passCount,
                    'quarantine_count' => $quarantineCount,
                    'reject_count' => $rejectCount,
                    'compliance_rate' => $complianceRate,
                    'source_ip_summary' => $ipSummary,
                    'failure_reasons' => $failures,
                ]
            );
        } catch (\Exception $e) {
            $this->line("Failed to update statistics: " . $e->getMessage());
            Log::error('Failed to update DMARC statistics', [
                'domain' => $report->domain ?? 'unknown',
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Save raw attachment for debugging
     */
    protected function saveRawAttachment(string $content, string $filename, string $from): void
    {
        try {
            $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
            $domain = $this->extractDomainFromEmail($from);
            $path = $this->storagePath . '/raw/' . date('Y/m/d');
            Storage::disk('local')->put(
                $path . '/' . $domain . '_' . $timestamp . '_' . $filename,
                $content
            );
            $this->line("Raw saved to: " . $path . '/' . $filename);
        } catch (\Exception $e) {
            // Silent fail
        }
    }
    
    /**
     * Save extracted report to storage
     */
    protected function saveExtractedReport(string $content, string $originalFilename, string $from): void
    {
        try {
            $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
            $domain = $this->extractDomainFromEmail($from);
            $filename = $domain . '_' . $timestamp . '.xml';
            
            $path = $this->storagePath . '/' . date('Y/m/d');
            Storage::disk('local')->put($path . '/' . $filename, $content);
            $this->line("Saved to: " . $path . '/' . $filename);
        } catch (\Exception $e) {
            $this->line("Failed to save report: " . $e->getMessage());
        }
    }
    
    /**
     * Get element value from DOMDocument
     */
    protected function getElementValue(\DOMNode $parent, string $tagName): ?string
    {
        $elements = $parent->getElementsByTagName($tagName);
        if ($elements->length > 0) {
            return $elements->item(0)->textContent;
        }
        return null;
    }
    
    /**
     * Extract domain from email address
     */
    protected function extractDomainFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) === 2) {
            $domain = explode('.', $parts[1]);
            return $domain[0] ?? 'unknown';
        }
        return 'unknown';
    }
    
    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}