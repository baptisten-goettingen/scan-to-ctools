<?php

namespace App\Console\Commands;

use CTApi\CTConfig;
use CTApi\CTClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\ScanProcessor;
use CTApi\Models\Wiki\WikiCategory\WikiCategoryRequest;
use CTApi\Models\Wiki\WikiPage\WikiPageTreeNode;
use CTApi\Utils\CTUtil;

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

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Konfiguriere den ChurchTools API Client
        CTConfig::setApiUrl(config('churchtools.api_url'));
        CTConfig::authWithCredentials(
            config('churchtools.username'),
            config('churchtools.password')
        );

        $override = $this->option('scan-dir');
        $dir = $override ? rtrim($override, '/\\') : rtrim(config('scan.in_dir'), '/');

        Log::info('scans:check gestartet', ['dir' => $dir]);
        $this->info("Starte Prüfung im Ordner: {$dir}");

        $destDir = storage_path('app/private');

        // Verarbeite Dateien mit ScanProcessor
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

        // Zeige gelöschte Dateien
        foreach ($result['deleted'] as $d) {
            $this->line("Gelöscht (kein PDF): {$d}");
        }

        // Wenn PDFs verschoben wurden, versuche Upload ins Wiki
        if (!empty($result['moved'])) {
            foreach ($result['moved'] as $filename) {
                $filepath = $destDir . DIRECTORY_SEPARATOR . $filename;
                $this->info("Lade Datei ins Wiki hoch: {$filename}");
               
                try {
                    Log::info('scans:check | upload start', [
                        'file' => $filename,
                        'endpoint' => "/api/files/wiki/7",
                        'filepath' => $filepath,
                    ]);

                    $csrfToken = json_decode(CTClient::getClient()->get("/api/csrftoken")->getBody()->getContents())->data;

                    $apiUrl = sprintf(
                        "%s/api/files/%s/%s",
                        config('churchtools.api_url'),
                        config('churchtools.wiki_domain'),
                        config('churchtools.wiki_domain_identifier')
                    );
                    $resultString=$this->uploadPerCurl($apiUrl, $csrfToken, $filepath);

                    Log::info('scans:check | upload success', [
                        'file' => $filename,
                        'response' => $resultString
                    ]);                   
                    $this->info("Hochgeladen: {$filename}");
                 
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

    private function uploadPerCurl(string $apiUrl, string $csrfToken, string $filepath): string
    {
          $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "content-type:multipart/form-data",
                "csrf-token:" . $csrfToken
            ]);
        // Set Cookie
        $cookie = CTConfig::getSessionCookie();
        if ($cookie != null) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie["Name"] . '=' . $cookie["Value"]);
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ["files[]" => curl_file_create($filepath)]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resultString = (string)curl_exec($ch);
        curl_close($ch);
        return $resultString;
    }
}
