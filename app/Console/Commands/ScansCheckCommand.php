<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScansCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:scans-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prüft den SCAN_IN-Ordner auf neue, stabile Dateien und meldet sie im Log.';

    
    public function handle(): int
    {
        $dir = rtrim(config('scan.in_dir'), '/');

        // Log in laravel.log
        Log::info('scans:check gestartet', ['dir' => $dir]);

        // Nur Shell-Output (sichtbar beim manuellen Aufruf, NICHT in laravel.log):
        $this->info("Starte Prüfung im Ordner: {$dir}");

        if (!$dir || !is_dir($dir)) {
            Log::error('scans:check | SCAN_IN invalid', ['dir' => $dir]);
            $this->error("SCAN_IN-Verzeichnis fehlt/ungültig: {$dir}");
            return self::FAILURE;
        }

        // … weitere Prüfung, z. B. Dateien zählen
        $files = glob($dir . '/*');
        Log::info('scans:check | Dateien gefunden', ['count' => count($files)]);

        Log::info('scans:check erfolgreich beendet');
        return self::SUCCESS;
    }

}
