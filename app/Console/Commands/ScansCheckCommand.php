<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use App\Services\ScanProcessor;
use App\Services\ChurchToolsService;

class ScansCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:scans-check {--scan-dir= : Optional override for scan directory}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks the SCAN_IN folder for new files, moves PDFs, and uploads them to the ChurchTools Wiki.';
    
    private ChurchToolsService $churchToolsService;

    public function __construct()
    {
        parent::__construct();

        $this->churchToolsService = new ChurchToolsService(
            config('churchtools.api_url'),
            config('churchtools.username'),
            config('churchtools.password'),
            config('churchtools.wiki_domain'),
            config('churchtools.wiki_domain_identifier')
        );
    }

    public function handle(): int
    {
        $override = $this->option('scan-dir');
        $dir = $override ? rtrim($override, '/\\') : rtrim(config('scan.in_dir'), '/');

        Log::info('scans:check gestartet', ['dir' => $dir]);
        $this->info("Starting scan in folder: {$dir}");

        $destDir = storage_path('app/private');

        $result = ScanProcessor::processDirectory(
            $dir,
            $destDir,
            function ($level, $message, $context = []) {
                Log::{$level}('scans:check | ' . $message, $context);
            },
            function ($line) {
                $this->line($line);
            }
        );

        foreach ($result['deleted'] as $d) {
            $this->line("Deleted (not a PDF): {$d}");
        }

        if (!empty($result['moved'])) {
            $csrfToken = $this->churchToolsService->getCsrfToken();
            if (!$csrfToken) {
                $this->error("Failed to retrieve CSRF token.");
                return self::FAILURE;
            }

            foreach ($result['moved'] as $filename) {
                $filepath = $destDir . DIRECTORY_SEPARATOR . $filename;
                $this->info("Uploading file to Wiki: {$filename}");

                try {
                    $response = $this->churchToolsService->uploadFile($filepath, $csrfToken);
                    Log::info('scans:check | upload success', [
                        'file' => $filename,
                        'response' => $response
                    ]);
                    $this->info("Uploaded: {$filename}");

                    // Delete file after successful upload
                    if (File::exists($filepath)) {
                        File::delete($filepath);
                        $this->info("File deleted: {$filename}");
                        Log::info('scans:check | file deleted', ['file' => $filename]);
                    }
                } catch (\Exception $e) {
                    $this->error("Upload failed for {$filename}: " . $e->getMessage());
                    Log::error('scans:check | upload failed', [
                        'file' => $filename,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $result['errors'][] = "Upload failed: {$filename}";
                }
            }
        }

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $this->info('Processing completed.');
        Log::info('scans:check finished successfully');
        return self::SUCCESS;
    }
}
