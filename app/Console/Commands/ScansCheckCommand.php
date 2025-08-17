<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\ScanProcessor;

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
    protected $description = 'Prüft den SCAN_IN-Ordner auf neue, stabile Dateien und meldet sie im Log.';

    public function handle(): int
    {
        $override = $this->option('scan-dir');
        $dir = $override ? rtrim($override, '/\\') : rtrim(config('scan.in_dir'), '/');

        Log::info('scans:check gestartet', ['dir' => $dir]);
        $this->info("Starte Prüfung im Ordner: {$dir}");

        $destDir = storage_path('app/private');

        // delegiere an ScanProcessor
        $result = ScanProcessor::processDirectory(
            $dir,
            $destDir,
            function ($level, $message, $context = []) {
                // einfache Brücke zu Laravel-Log
                Log::{$level}('scans:check | ' . $message, $context);
            },
            function ($line) {
                // gibt auf Konsole aus
                echo $line . PHP_EOL;
            }
        );

        foreach ($result['deleted'] as $d) {
            $this->line("Gelöscht (kein PDF): {$d}");
        }
        foreach ($result['moved'] as $m) {
            $this->line("Verschoben: {$m}");
        }
        foreach ($result['errors'] as $err) {
            $this->error("Fehler: {$err}");
        }

        if (count($result['errors']) > 0) {
            Log::error('scans:check beendet mit Fehlern', ['errors' => $result['errors']]);
            return self::FAILURE;
        }

        Log::info('scans:check erfolgreich beendet');
        return self::SUCCESS;
    }
}
