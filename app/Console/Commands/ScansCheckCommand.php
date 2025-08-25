<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
    protected $description = 'Prüft den SCAN_IN-Ordner auf neue Dateien, verschiebt PDFs und lädt sie ins ChurchTools-Wiki hoch.';
    
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
            $this->line("Deleted (no PDF file): {$d}");
        }

        if (!empty($result['moved'])) {
            $csrfToken = $this->churchToolsService->getCsrfToken();
            if (!$csrfToken) {
                $this->error("CSRF-Token could not be retrieved. Aborting uploads.");
                return self::FAILURE;
            }

            foreach ($result['moved'] as $filename) {
                $filepath = $destDir . DIRECTORY_SEPARATOR . $filename;
                $this->info("Upload file to wiki: {$filename}");

                try {
                    $response = $this->churchToolsService->uploadFile($filepath, $csrfToken);
                    Log::info('scans:check | upload success', [
                        'file' => $filename,
                        'response' => $response
                    ]);
                    $this->info("Uploaded: {$filename}");
                } catch (\Exception $e) {
                    $this->error("Upload fehlgeschlagen für {$filename}: " . $e->getMessage());
                    Log::error('scans:check | upload failed', [
                        'file' => $filename,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $result['errors'][] = "Upload fehlgeschlagen: {$filename}";
                }
            }
        }

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $this->info('Verarbeitung abgeschlossen.');
        Log::info('scans:check erfolgreich beendet');
        return self::SUCCESS;
    }
}
