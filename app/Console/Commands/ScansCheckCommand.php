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

    protected string $stateFile;

    public function __construct()
    {
        parent::__construct();
        // einfacher Zustands-Speicher in storage/app
        $this->stateFile = storage_path('app/scan_seen.json');
    }

    /**
     * Execute the console command.
     */
   public function handle(): int
    {
        $dir = rtrim(env('SCAN_IN', ''), '/');
        if (!$dir || !is_dir($dir)) {
            $this->error("SCAN_IN-Verzeichnis fehlt/ungültig: {$dir}");
            Log::error("scans:check | SCAN_IN invalid: {$dir}");
            return self::FAILURE;
        }

        $allowed = collect(explode(',', (string)env('ALLOWED_EXT', '')))
            ->filter()->map(fn($e) => strtolower(trim($e)));

        $minStable = (int) env('MIN_STABLE_SEC', 3);

        // State laden (bereits gesehene Dateien)
        $seen = $this->loadState();

        $files = collect(scandir($dir))
            ->reject(fn($n) => in_array($n, ['.', '..']))
            ->map(fn($n) => $dir.'/'.$n)
            ->filter(fn($p) => is_file($p))
            ->sort();

        $newStable = [];

        foreach ($files as $path) {
            $name = basename($path);
            $ext = strtolower('.'.pathinfo($name, PATHINFO_EXTENSION));

            if ($allowed->isNotEmpty() && !$allowed->contains($ext)) {
                // ignorieren, aber nicht als "gesehen" markieren
                continue;
            }

            // bereits gesehen? -> überspringen
            if (isset($seen[$name])) {
                continue;
            }

            // Stabilitäts-Check: Größe zweimal prüfen
            $size1 = filesize($path);
            if ($size1 === false) {
                continue;
            }
            sleep(max(1, $minStable));
            $size2 = @filesize($path);
            if ($size2 === false || $size1 !== $size2) {
                // noch in Upload/Schreibvorgang
                continue;
            }

            $newStable[] = $path;
        }

        if (empty($newStable)) {
            $this->info('Keine neuen stabilen Dateien gefunden.');
            return self::SUCCESS;
        }

        foreach ($newStable as $path) {
            $name = basename($path);
            $this->line("Neu: {$name}");
            Log::info("scans:check | neue Datei entdeckt: {$name}", [
                'path' => $path,
                'bytes' => filesize($path),
                'mtime' => filemtime($path),
            ]);
            // als gesehen markieren
            $seen[$name] = [
                'mtime' => filemtime($path),
                'size'  => filesize($path),
                'seen_at' => now()->toIso8601String(),
            ];
        }

        $this->saveState($seen);

        return self::SUCCESS;
    }

    protected function loadState(): array
    {
        if (is_file($this->stateFile)) {
            $json = @file_get_contents($this->stateFile);
            if ($json !== false) {
                $data = json_decode($json, true);
                if (is_array($data)) return $data;
            }
        }
        return [];
    }

    protected function saveState(array $state): void
    {
        @file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
}
