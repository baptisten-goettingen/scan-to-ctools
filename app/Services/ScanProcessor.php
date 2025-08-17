<?php

namespace App\Services;

class ScanProcessor
{
    /**
     * Process source directory: delete non-PDFs, move PDFs to dest dir.
     *
     * @param string $srcDir
     * @param string $destDir
     * @param callable|null $logger function(string $level, string $message, array $context = []) {}
     * @param callable|null $printer function(string $line) {}
     * @return array ['moved' => [], 'deleted' => [], 'errors' => []]
     */
    public static function processDirectory(string $srcDir, string $destDir, ?callable $logger = null, ?callable $printer = null): array
    {
        $moved = [];
        $deleted = [];
        $errors = [];

        // Normalize paths
        $srcDir = rtrim($srcDir, DIRECTORY_SEPARATOR);
        $destDir = rtrim($destDir, DIRECTORY_SEPARATOR);

        if ($logger) { $logger('info', 'scans:process started', ['src' => $srcDir, 'dest' => $destDir]); }
        if (!is_dir($srcDir)) {
            $errors[] = "Source directory missing: {$srcDir}";
            if ($logger) { $logger('error', 'scans:process | src invalid', ['src' => $srcDir]); }
            return compact('moved', 'deleted', 'errors');
        }

        if (!is_dir($destDir)) {
            if (!@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                $errors[] = "Cannot create dest dir: {$destDir}";
                if ($logger) { $logger('error', 'scans:process | cannot create dest', ['dest' => $destDir]); }
                return compact('moved', 'deleted', 'errors');
            }
        }

        $files = glob($srcDir . DIRECTORY_SEPARATOR . '*');
        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $filename = basename($path);

            if ($ext !== 'pdf') {
                try {
                    if (@unlink($path)) {
                        $deleted[] = $filename;
                        if ($printer) { $printer("Gelöscht (kein PDF): {$filename}"); }
                        if ($logger) { $logger('info', 'deleted non-pdf', ['file' => $path, 'ext' => $ext]); }
                    } else {
                        $errors[] = "Could not delete file: {$path}";
                        if ($logger) { $logger('error', 'delete failed', ['file' => $path]); }
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Error deleting {$filename}: " . $e->getMessage();
                    if ($logger) { $logger('error', 'delete exception', ['file' => $path, 'error' => $e->getMessage()]); }
                }
                continue;
            }

            // PDF -> move to dest (ensure unique)
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $target = $destDir . DIRECTORY_SEPARATOR . $filename;
            $counter = 0;
            while (file_exists($target)) {
                $counter++;
                $target = $destDir . DIRECTORY_SEPARATOR . $base . '_' . $counter . '.pdf';
            }

            $movedFlag = false;
            try {
                if (@rename($path, $target)) {
                    $movedFlag = true;
                } else {
                    if (@copy($path, $target)) {
                        @unlink($path);
                        $movedFlag = true;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "Error moving {$filename}: " . $e->getMessage();
                if ($logger) { $logger('error', 'move exception', ['file' => $path, 'error' => $e->getMessage()]); }
            }

            if ($movedFlag) {
                $moved[] = basename($target);
                if ($printer) { $printer("Verschoben: {$filename} -> {$target}"); }
                if ($logger) { $logger('info', 'pdf moved', ['from' => $path, 'to' => $target]); }
            } else {
                $errors[] = "Move failed for {$filename}";
                if ($logger) { $logger('error', 'move failed', ['file' => $path, 'target' => $target]); }
            }
        }

        if ($logger) { $logger('info', 'scans:process finished', ['moved' => count($moved), 'deleted' => count($deleted), 'errors' => $errors]); }

        return compact('moved', 'deleted', 'errors');
    }
}