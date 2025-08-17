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

        [$srcDir, $destDir] = self::normalizePaths($srcDir, $destDir);

        if ($logger) { $logger('info', 'scans:process started', ['src' => $srcDir, 'dest' => $destDir]); }

        if (!is_dir($srcDir)) {
            $errors[] = "Source directory missing: {$srcDir}";
            if ($logger) { $logger('error', 'scans:process | src invalid', ['src' => $srcDir]); }
            return compact('moved', 'deleted', 'errors');
        }

        if (!self::ensureDestDir($destDir, $errors, $logger)) {
            return compact('moved', 'deleted', 'errors');
        }

        $files = glob($srcDir . DIRECTORY_SEPARATOR . '*');
        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }

            $filename = basename($path);

            try {
                if (!self::isPdfExtension($path)) {
                    if (self::deleteNonPdf($path, $filename, $deleted, $logger, $printer) === false) {
                        $errors[] = "Could not delete file: {$path}";
                    }
                    continue;
                }

                $target = self::ensureUniqueTarget($destDir, $filename);
                $movedFlag = self::tryMoveFile($path, $target);

                if ($movedFlag) {
                    $moved[] = basename($target);
                    if ($printer) { $printer("Verschoben: {$filename} -> {$target}"); }
                    if ($logger) { $logger('info', 'pdf moved', ['from' => $path, 'to' => $target]); }
                    self::trySetOwnership($target, $logger);
                } else {
                    $errors[] = "Move failed for {$filename}";
                    if ($logger) { $logger('error', 'move failed', ['file' => $path, 'target' => $target]); }
                }
            } catch (\Throwable $e) {
                $errors[] = ($e->getMessage() ?: "Unhandled error with file {$filename}");
                if ($logger) { $logger('error', 'processing exception', ['file' => $path, 'error' => $e->getMessage()]); }
            }
        }

        if ($logger) { $logger('info', 'scans:process finished', ['moved' => count($moved), 'deleted' => count($deleted), 'errors' => $errors]); }

        return compact('moved', 'deleted', 'errors');
    }

    private static function normalizePaths(string $src, string $dest): array
    {
        return [rtrim($src, DIRECTORY_SEPARATOR), rtrim($dest, DIRECTORY_SEPARATOR)];
    }

    private static function ensureDestDir(string $destDir, array &$errors, ?callable $logger = null): bool
    {
        if (!is_dir($destDir)) {
            if (!@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                $errors[] = "Cannot create dest dir: {$destDir}";
                if ($logger) { $logger('error', 'scans:process | cannot create dest', ['dest' => $destDir]); }
                return false;
            }
        }
        return true;
    }

    private static function isPdfExtension(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext === 'pdf';
    }

    private static function deleteNonPdf(string $path, string $filename, array &$deleted, ?callable $logger = null, ?callable $printer = null): bool
    {
        try {
            if (@unlink($path)) {
                $deleted[] = $filename;
                if ($printer) { $printer("Gelöscht (kein PDF): {$filename}"); }
                if ($logger) { $logger('info', 'deleted non-pdf', ['file' => $path, 'ext' => pathinfo($path, PATHINFO_EXTENSION)]); }
                return true;
            } else {
                if ($logger) { $logger('error', 'delete failed', ['file' => $path]); }
                return false;
            }
        } catch (\Throwable $e) {
            if ($logger) { $logger('error', 'delete exception', ['file' => $path, 'error' => $e->getMessage()]); }
            return false;
        }
    }

    private static function ensureUniqueTarget(string $destDir, string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'pdf';
        $target = $destDir . DIRECTORY_SEPARATOR . $base . '.' . $ext;
        $counter = 0;
        while (file_exists($target)) {
            $counter++;
            $target = $destDir . DIRECTORY_SEPARATOR . $base . '_' . $counter . '.' . $ext;
        }
        return $target;
    }

    private static function tryMoveFile(string $from, string $to): bool
    {
        try {
            if (@rename($from, $to)) {
                return true;
            }
            if (@copy($from, $to)) {
                @unlink($from);
                return true;
            }
        } catch (\Throwable) {
            // swallow, caller records error
        }
        return false;
    }

    private static function trySetOwnership(string $target, ?callable $logger = null): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        try {
            $desiredUser = 'www-data';
            if (function_exists('posix_getpwnam')) {
                $pw = posix_getpwnam($desiredUser);
                if ($pw && isset($pw['uid'])) {
                    @chown($target, $pw['uid']);
                    if (isset($pw['gid'])) {
                        @chgrp($target, $pw['gid']);
                    }
                } else {
                    @chown($target, $desiredUser);
                    @chgrp($target, $desiredUser);
                }
            } else {
                @chown($target, $desiredUser);
                @chgrp($target, $desiredUser);
            }

            if (function_exists('posix_getpwuid')) {
                $ownerInfo = posix_getpwuid(@fileowner($target));
                $ownerOk = ($ownerInfo && (($ownerInfo['name'] ?? '') === $desiredUser));
            } else {
                $ownerOk = true; // can't verify
            }

            if ($ownerOk) {
                if ($logger) { $logger('info', 'ownership set', ['file' => $target, 'user' => $desiredUser]); }
            } else {
                if ($logger) { $logger('warning', 'ownership not changed to www-data', ['file' => $target]); }
            }
        } catch (\Throwable $e) {
            if ($logger) { $logger('warning', 'chown failed', ['file' => $target, 'error' => $e->getMessage()]); }
        }
    }
}