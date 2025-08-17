# scan-to-ctools

Kleine Laravel-Anwendung zum automatischen Aufbereiten von Scans und (optional) Hochladen in ChurchTools.

Kurz: Die App prüft ein Eingangsverzeichnis auf neue Dateien, löscht alle Nicht‑PDFs und verschiebt gefundene PDFs in `storage/app/private`. Ein schlanker ChurchTools‑Client (optional) kann Dateien per API hochladen.

## Features
- Überwacht ein Scan‑Eingangsverzeichnis (configurierbar).
- Nur PDF-Dateien werden behalten und in `storage/app/private` verschoben.
- Nicht‑PDFs werden gelöscht.
- CLI‑Command zum Ausführen der Prüfung.
- Optionaler ChurchTools‑Client zum Upload von Dateien (konfigurierbar via .env + config).

## Anforderungen
- PHP 8.2+
- Composer
- Laravel (in diesem Projekt bereits vorhanden)

## Installation
1. Repository klonen
   ```
   git clone <repo-url>
   cd scan-to-ctools
   ```
2. Abhängigkeiten installieren
   ```
   composer install
   ```
3. Beispiel‑Umgebung kopieren und anpassen
   ```
   cp .env.example .env
   ```
4. APP_KEY setzen (falls benötigt)
   ```
   php artisan key:generate
   ```

## Konfiguration
Einstellungen werden über `.env` und `config/*.php` gesteuert.

Wichtige .env‑Variablen (Beispiele):
- SCAN_IN=/srv/scans/incoming         # Eingangsordner für Scans
- CHURCHTOOLS_API_URL=https://your.church.tools
- CHURCHTOOLS_API_TOKEN=eyJ...
- CHURCHTOOLS_UPLOAD_ENDPOINT=/api/media
- CHURCHTOOLS_TIMEOUT=30

Relevante config‑Dateien:
- `config/scan.php` — enthält `in_dir` (Pfad zum SCAN_IN)
- `config/churchtools.php` — API‑URL, Token, Upload‑Endpoint, Timeout

(Die im Projekt vorhandenen config‑Dateien prüfen und `.env` entsprechend anpassen.)

## Commands
Alle Commands mit Artisan aufrufen.

- app:scans-check
  - Zweck: Prüft das konfigurierte Eingangsverzeichnis, löscht Nicht‑PDFs und verschiebt PDFs nach `storage/app/private`.
  - Usage:
    ```
    php artisan app:scans-check
    php artisan app:scans-check --scan-dir=/pfad/zum/ordner
    ```
  - Log: Einträge landen in `storage/logs/laravel.log` (Prefix `scans:check`).

- ct:upload (optional)
  - Zweck: Lädt eine Datei per ChurchTools‑API hoch (implementiert in `App\Services\ChurchToolsClient`).
  - Usage:
    ```
    php artisan ct:upload /path/to/file.pdf --field=title="Mein Scan" --field=target=media
    ```
  - Konfiguration über `config/churchtools.php` / `.env`.

## Speicherort & Berechtigungen
Zielordner: `storage/app/private`

Empfehlung zur Vermeidung von Berechtigungsproblemen (auf dem Server als root / sudo ausführen):
```
sudo chown -R www-data:www-data /var/www/scan-to-ctools/storage/app/private
sudo chmod -R g+rwX /var/www/scan-to-ctools/storage/app/private
sudo chmod g+s /var/www/scan-to-ctools/storage/app/private
# falls Scanner-Prozess als anderer User läuft:
sudo usermod -aG www-data scanner
```
Alternativ ACLs:
```
sudo setfacl -R -m u:www-data:rwx,u:scanner:rwx /var/www/scan-to-ctools/storage/app/private
sudo setfacl -R -d -m g:www-data:rwX,u:scanner:rwx /var/www/scan-to-ctools/storage/app/private
```

Hinweis: Die Applikation versucht nicht mehr, Datei‑Ownership per PHP zu setzen — Berechtigungen/Gruppe sollten systemseitig passend konfiguriert werden.

## Tests
Unit‑Tests (reines PHPUnit) vorhanden für die Scan‑Verarbeitung:
```
vendor/bin/phpunit --filter ScansCheckCommandTest
```
Die Logik ist in `app/Services/ScanProcessor.php` kapselt und lässt sich ohne Laravel‑Bootstrapping testen.

## Troubleshooting / Logs
- Logs: `storage/logs/laravel.log` — Suche nach `scans:check`.
- Häufige Ursache für Probleme: falsche Rechte auf `storage/app/private` oder falsche `SCAN_IN`‑Path.
- Ownership‑Warnings: zeigen an, dass der System‑User, der die Datei erstellt hat, nicht von PHP geändert werden konnte — siehe Abschnitt „Speicherort & Berechtigungen“.

## Code‑Orte (schnell)
- Scan‑Logik: `app/Services/ScanProcessor.php`
- CLI Command: `app/Console/Commands/ScansCheckCommand.php`
- ChurchTools‑Client: `app/Services/ChurchToolsClient.php`
- ChurchTools‑Config: `config/churchtools.php`

## Lizenz