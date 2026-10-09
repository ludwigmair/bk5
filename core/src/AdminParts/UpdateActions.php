<?php

declare(strict_types=1);

namespace Core\AdminParts;

use Core\CMS;
use Core\Env;

/**
 * Teil von Core\Admin (per `use` eingebunden, läuft im Kontext von Admin –
 * $this->cms, Konstanten und alle anderen Admin-Methoden stehen zur Verfügung).
 *
 * Core- und Components-Updates: prüfen, laden, installieren, Update-Pakete bauen, Server-Backup-Endpunkt.
 */
trait UpdateActions
{
    /**
     * Führt checkUpdate() aus und leitet danach um statt direkt zu rendern -
     * konsistent mit jeder anderen Aktion (siehe redirect()/redirectToPanel()),
     * landet dadurch zuverlässig auf panel-update-package statt auf einem
     * zufällig noch offenen anderen Panel.
     */
    private function runCheckUpdate(): void
    {
        $_SESSION['update_info'] = $this->checkUpdate();
        header('Location: ?admin=1#panel-update-package');
        exit;
    }

    /** @return array{ok:bool,message:string,latest?:string,url?:string,incompatible?:list<string>} */
    private function checkUpdate(): array
    {
        $current = (string) (CMS::readJson($this->cms->root() . '/core/version.json')['version'] ?? '0');
        $manifestUrl = (string) ($this->cms->config()['update']['manifest_url'] ?? '');
        if ($manifestUrl === '' || !str_starts_with($manifestUrl, 'https://')) {
            return ['ok' => false, 'message' => 'Bitte eine HTTPS-Manifest-URL in data/config.json setzen.'];
        }

        $raw = @file_get_contents($manifestUrl, false, self::httpContext());
        if ($raw === false) {
            return ['ok' => false, 'message' => 'Manifest nicht erreichbar.'];
        }
        $manifest = json_decode($raw, true);
        $latest = (string) ($manifest['version'] ?? '');
        $zip = (string) ($manifest['zip_url'] ?? '');
        if ($latest === '' || $zip === '') {
            return ['ok' => false, 'message' => 'Manifest unvollständig.'];
        }

        if (version_compare($latest, $current, '<=')) {
            return ['ok' => true, 'message' => 'Core ist aktuell (' . $current . ').', 'latest' => $latest];
        }

        return [
            'ok' => true,
            'message' => 'Neue Version ' . $latest . ' (aktuell ' . $current . ').',
            'latest' => $latest,
            'url' => $zip,
            'sha256' => strtolower(trim((string) ($manifest['zip_sha256'] ?? ''))),
            'notes' => (string) ($manifest['notes'] ?? ''),
            'incompatible' => $this->incompatibleComponents($latest),
        ];
    }

    /**
     * Components/Layout-Varianten, deren schema.json einen anderen Core-Hauptversionsstand
     * (`requires_core`) deklarieren als die Version, auf die aktualisiert werden würde – ein
     * Hinweis, dass sich der Plugin-Vertrag (z. B. data.php-Hook-Signatur) geändert haben könnte
     * und diese Components vor dem Update geprüft werden sollten.
     *
     * @return list<string>
     */
    private function incompatibleComponents(string $targetVersion): array
    {
        $targetMajor = (int) explode('.', $targetVersion)[0];
        $out = [];

        foreach ($this->scanSchemas() as $schema) {
            $requires = (string) ($schema['requires_core'] ?? '');
            if ($requires === '') {
                continue;
            }
            if ((int) explode('.', $requires)[0] !== $targetMajor) {
                $out[] = (string) ($schema['label'] ?? $schema['type'] ?? '?');
            }
        }

        foreach (['header', 'footer', 'topbar', 'stickybar', 'cookiebanner'] as $region) {
            $schema = $this->layoutSchemaFor($region);
            $requires = (string) ($schema['requires_core'] ?? '');
            if ($requires === '') {
                continue;
            }
            if ((int) explode('.', $requires)[0] !== $targetMajor) {
                $out[] = (string) ($schema['label'] ?? $region);
            }
        }

        return $out;
    }

    private function runUpdate(): void
    {
        $info = $this->checkUpdate();
        $zipUrl = (string) ($info['url'] ?? '');
        if ($zipUrl === '' || !str_starts_with($zipUrl, 'https://')) {
            $this->redirectToPanel($info['message'] ?? 'Kein Update.', 'panel-update-package');
        }

        $tmpZip = $this->cms->root() . '/cache/core-update.zip';
        $tmpDir = $this->cms->root() . '/cache/core-update';
        $backup = $this->cms->root() . '/cache/core-backup-' . date('YmdHis');

        $zipData = @file_get_contents($zipUrl, false, self::httpContext());
        if ($zipData === false || file_put_contents($tmpZip, $zipData) === false) {
            $this->redirectToPanel('ZIP konnte nicht geladen werden.', 'panel-update-package');
        }
        if (!self::zipMatchesManifest($zipData, (string) ($info['sha256'] ?? ''))) {
            @unlink($tmpZip);
            $this->redirectToPanel('Prüfsumme des Update-Pakets stimmt nicht mit dem Manifest überein – Update abgebrochen, nichts verändert.', 'panel-update-package');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            $this->redirectToPanel('ZIP ungültig.', 'panel-update-package');
        }

        if (is_dir($tmpDir)) {
            $this->rrmdir($tmpDir);
        }
        mkdir($tmpDir, 0775, true);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || str_contains($name, '..')) {
                $zip->close();
                $this->redirectToPanel('Unsicherer ZIP-Eintrag.', 'panel-update-package');
            }
        }
        $zip->extractTo($tmpDir);
        $zip->close();

        $newCore = $this->findCoreDir($tmpDir);
        if ($newCore === null) {
            $this->redirectToPanel('Im Archiv fehlt ein /core-Ordner.', 'panel-update-package');
        }

        $core = $this->cms->root() . '/core';
        if (!@rename($core, $backup)) {
            $this->redirectToPanel('Backup konnte nicht angelegt werden, Update abgebrochen.', 'panel-update-package');
        }
        if (!@rename($newCore, $core)) {
            $restored = @rename($backup, $core);
            $this->redirectToPanel($restored
                ? 'Core konnte nicht ersetzt werden, Backup wiederhergestellt.'
                : 'Core konnte nicht ersetzt werden. Backup liegt unter ' . basename($backup) . ', bitte manuell zurückspielen.', 'panel-update-package');
        }

        @unlink($tmpZip);
        $this->rrmdir($tmpDir);
        $this->afterCodeUpdate('core-backup-');
        $this->redirectToPanel('Core aktualisiert. Instanzdaten (JSON) unverändert.', 'panel-update-package');
    }

    private function findCoreDir(string $dir): ?string
    {
        if (is_file($dir . '/version.json') && is_dir($dir . '/src')) {
            return $dir;
        }
        if (is_dir($dir . '/core') && is_file($dir . '/core/version.json')) {
            return $dir . '/core';
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $child) {
            $found = $this->findCoreDir($child);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * Stream-Context mit Timeout für Manifest-/ZIP-Downloads (sonst kein Limit,
     * analog zu GoogleReview.php). Kein Rückgabetyp deklariert, da PHP für
     * `resource` (der Rückgabetyp von stream_context_create()) kein Type-Hint kennt.
     */
    private static function httpContext()
    {
        return stream_context_create(['http' => ['timeout' => 8]]);
    }

    /**
     * Ältere Manifeste haben noch kein zip_sha256 – dann nur prüfen, wenn eins da
     * ist (sonst könnte keine alte Instanz mehr aktualisieren). Ist eins da, muss
     * es exakt passen.
     */
    private static function zipMatchesManifest(string $zipData, string $expected): bool
    {
        return $expected === '' || hash_equals($expected, hash('sha256', $zipData));
    }

    /**
     * Nach Core-/Components-Update: kompilierte Twig-Templates und Seiten-Cache
     * leeren (ZIP-Dateien behalten ihr altes mtime, auto_reload hielte den Cache
     * sonst für aktuell und zeigte die alte Admin-Oberfläche weiter) und nur die
     * neuesten Backup-Ordner dieses Typs behalten.
     */
    private function afterCodeUpdate(string $backupPrefix): void
    {
        $cache = $this->cms->root() . '/cache';
        foreach (['twig', 'pages'] as $sub) {
            if (is_dir($cache . '/' . $sub)) {
                $this->rrmdir($cache . '/' . $sub);
            }
        }
        $backups = glob($cache . '/' . $backupPrefix . '*', GLOB_ONLYDIR) ?: [];
        rsort($backups); // Zeitstempel im Namen -> neueste zuerst
        foreach (array_slice($backups, self::KEEP_CODE_BACKUPS) as $old) {
            $this->rrmdir($old);
        }
    }

    /**
     * Entwickler-Werkzeug für die Provider-Seite von "Ein Update bereitstellen"
     * (docs/UPDATE-CORE.md): zählt core/version.json real hoch (wie im manuellen
     * Ablauf beschrieben) und zippt den aktuellen core/-Ordner danach nach
     * cache/core-<version>.zip. Ersetzt nur die Schritte 1+2 der Doku - das
     * öffentliche Hosten von ZIP+Manifest bleibt manuell, das kann kein
     * Server-Prozess für einen selbst übernehmen.
     */
    private function buildUpdatePackage(): void
    {
        $version = trim((string) ($_POST['version'] ?? ''));
        $current = (string) (CMS::readJson($this->cms->root() . '/core/version.json')['version'] ?? '0');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $this->redirectToPanel('Ungültige Versionsnummer (Format x.y.z erwartet).', 'panel-update-package');
        }
        if (version_compare($version, $current, '<=')) {
            $this->redirectToPanel('Neue Version muss größer als die aktuelle (' . $current . ') sein.', 'panel-update-package');
        }

        $core = $this->cms->root() . '/core';
        $checksum = $this->coreChecksum();
        CMS::writeJson($core . '/version.json', ['version' => $version, 'released' => date('Y-m-d'), 'checksum' => $checksum]);

        $cacheDir = $this->cms->root() . '/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        $zipName = 'core-' . $version . '.zip';
        $zipPath = $cacheDir . '/' . $zipName;
        if (is_file($zipPath)) {
            unlink($zipPath);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->redirectToPanel('ZIP konnte nicht angelegt werden.', 'panel-update-package');
        }
        $this->addDirToZip($zip, $core, 'core');
        $zip->close();

        $this->redirectToPanel('Update-Paket ' . $version . ' erstellt (core/version.json wurde mit hochgezählt). Noch nicht live für andere Instanzen – dafür zusätzlich ',
            'panel-update-package',
            'php dev/build-release.php ' . $version . ' --repo ludwigmair/onepage-cms-releases',
            ' ausführen (siehe docs/UPDATE-CORE.md).');
    }

    private function addDirToZip(\ZipArchive $zip, string $dir, string $localBase): void
    {
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.DS_Store') {
                continue;
            }
            $path = $dir . '/' . $item;
            $local = $localBase . '/' . $item;
            if (is_dir($path)) {
                $zip->addEmptyDir($local);
                $this->addDirToZip($zip, $path, $local);
            } else {
                $zip->addFile($path, $local);
            }
        }
    }

    /**
     * Backup-Endpunkt für CI-Deploys (siehe dev/templates/deploy-staging.yml,
     * Schritt "Sicherung auf dem Server anlegen" - der Workflow ruft das VOR
     * dem eigentlichen Deploy auf, kein Login möglich, deshalb Bearer-Token
     * statt Session). Ohne BACKUP_TOKEN in .env ist der Endpunkt komplett
     * deaktiviert (404) statt "offen ohne Prüfung" - ein leerer erwarteter
     * Wert darf nie automatisch "passt" bedeuten.
     *
     * Sichert nur data/ (Content, Config, Bearbeitungsverlauf, Anfragen) und
     * uploads/ (Bilder) - der Code selbst kommt aus Git und braucht kein Backup.
     *
     * Ablage in data/backups/ (per .htaccess gesperrt, vom Deploy nie angefasst).
     * Früher lag sie in cache/backups/ - den cache/-Ordner hat der Deploy-Workflow
     * aber VOR jedem Backup komplett gelöscht, es gab also nie mehr als das eine
     * Backup des letzten Deploys. data/backups/ selbst wird beim Zippen
     * übersprungen (sonst enthielte jedes Backup alle vorherigen).
     */
    private function runBackup(): void
    {
        header('Content-Type: application/json');

        $expected = trim(Env::get('BACKUP_TOKEN'));
        if ($expected === '') {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'backup endpoint not configured']);
            exit;
        }

        $provided = $this->bearerToken();
        if ($provided === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'invalid or missing token']);
            exit;
        }

        $root = $this->cms->root();
        $backupDir = $root . '/data/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'backup directory could not be created']);
            exit;
        }
        // Noch vorhandene Backups vom alten Ablageort übernehmen.
        foreach (glob($root . '/cache/backups/backup-*.zip') ?: [] as $legacy) {
            @rename($legacy, $backupDir . '/' . basename($legacy));
        }

        $file = $backupDir . '/backup-' . date('Ymd-His') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'zip could not be created']);
            exit;
        }
        if (is_dir($root . '/data')) {
            $this->addDirToZipSkipping($zip, $root . '/data', 'data', ['backups']);
        }
        if (is_dir($root . '/uploads')) {
            $this->addDirToZip($zip, $root . '/uploads', 'uploads');
        }
        $zip->close();

        $this->pruneBackups($backupDir, 10);

        echo json_encode(['ok' => true, 'file' => basename($file), 'size' => filesize($file) ?: 0]);
        exit;
    }

    /** Bearer-Token aus dem Authorization-Header, mit Fallback auf ein "token"-Feld (POST/GET) für den Fall, dass der Header vom Server gefiltert wird. */
    private function bearerToken(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return trim($m[1]);
        }

        return trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
    }

    /** Behält nur die $keep neuesten Backups (Dateiname enthält den Zeitstempel, alphabetisch = chronologisch sortierbar). */
    private function pruneBackups(string $backupDir, int $keep): void
    {
        $files = glob($backupDir . '/backup-*.zip') ?: [];
        sort($files);
        $excess = count($files) - $keep;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * Findet bereits gebaute Pakete in cache/ (core-x.y.z.zip), neueste zuerst.
     * zip_url im Manifest bleibt bewusst ein Platzhalter: wo die ZIP landet
     * (Download hier im Admin + selbst hochladen, oder direkt woanders
     * bereitstellen), entscheidet man beim Veröffentlichen, nicht hier.
     *
     * @return list<array{version:string,file:string,manifest:string}>
     */
    private function listUpdatePackages(): array
    {
        $cacheDir = $this->cms->root() . '/cache';
        $files = is_dir($cacheDir) ? (glob($cacheDir . '/core-*.zip') ?: []) : [];

        $out = [];
        foreach ($files as $path) {
            $name = basename($path);
            if (!preg_match('/^core-(\d+\.\d+\.\d+)\.zip$/', $name, $m)) {
                continue;
            }
            $manifest = json_encode([
                'version' => $m[1],
                'zip_url' => '<öffentliche HTTPS-URL zur hochgeladenen ' . $name . '>',
                'zip_sha256' => (string) hash_file('sha256', $path),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $out[] = ['version' => $m[1], 'file' => $name, 'manifest' => (string) $manifest];
        }
        usort($out, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $out;
    }

    private function downloadUpdatePackage(): void
    {
        $file = basename((string) ($_GET['file'] ?? ''));
        $path = $this->cms->root() . '/cache/' . $file;
        if ($file === '' || !preg_match('/^core-\d+\.\d+\.\d+\.zip$/', $file) || !is_file($path)) {
            $this->redirectToPanel('Paket nicht gefunden.', 'panel-update-package');
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    /**
     * Löscht ein lokal gebautes, nicht mehr benötigtes Update-Paket aus cache/
     * (z. B. nachdem alle Ziel-Instanzen bereits aktualisiert sind). Betrifft nur
     * das lokale ZIP, kein bereits veröffentlichtes GitHub-Release.
     */
    private function deleteUpdatePackage(): void
    {
        $file = basename((string) ($_POST['file'] ?? ''));
        $path = $this->cms->root() . '/cache/' . $file;
        if ($file === '' || !preg_match('/^core-\d+\.\d+\.\d+\.zip$/', $file) || !is_file($path)) {
            $this->redirectToPanel('Paket nicht gefunden.', 'panel-update-package');
        }

        unlink($path);
        $this->redirectToPanel('Paket gelöscht: ' . $file, 'panel-update-package');
    }

    /**
     * Components-Update: exakt derselbe Mechanismus wie Core-Update (Manifest/ZIP,
     * Backup+Rollback, Checksum-Badge), nur für themes-and-plugins/components/
     * statt core/ - ein Paket für den ganzen Component-Pool (siehe
     * docs/UPDATE-COMPONENTS.md). content.json bleibt dabei unangetastet, genau
     * wie beim Core-Update.
     */
    private function runCheckComponentsUpdate(): void
    {
        $_SESSION['components_update_info'] = $this->checkComponentsUpdate();
        header('Location: ?admin=1#panel-components-update');
        exit;
    }

    /** @return array{ok:bool,message:string,latest?:string,url?:string} */
    private function checkComponentsUpdate(): array
    {
        $current = (string) (CMS::readJson($this->componentsDir() . '/version.json')['version'] ?? '0');
        $manifestUrl = (string) ($this->cms->config()['update']['components_manifest_url'] ?? '');
        if ($manifestUrl === '' || !str_starts_with($manifestUrl, 'https://')) {
            return ['ok' => false, 'message' => 'Bitte eine HTTPS-Manifest-URL in data/config.json setzen (update.components_manifest_url).'];
        }

        $raw = @file_get_contents($manifestUrl, false, self::httpContext());
        if ($raw === false) {
            return ['ok' => false, 'message' => 'Manifest nicht erreichbar.'];
        }
        $manifest = json_decode($raw, true);
        $latest = (string) ($manifest['version'] ?? '');
        $zip = (string) ($manifest['zip_url'] ?? '');
        if ($latest === '' || $zip === '') {
            return ['ok' => false, 'message' => 'Manifest unvollständig.'];
        }

        if (version_compare($latest, $current, '<=')) {
            return ['ok' => true, 'message' => 'Components sind aktuell (' . $current . ').', 'latest' => $latest];
        }

        return [
            'ok' => true,
            'message' => 'Neue Components-Version ' . $latest . ' (aktuell ' . $current . ').',
            'latest' => $latest,
            'url' => $zip,
            'sha256' => strtolower(trim((string) ($manifest['zip_sha256'] ?? ''))),
            'notes' => (string) ($manifest['notes'] ?? ''),
        ];
    }

    private function runComponentsUpdate(): void
    {
        $info = $this->checkComponentsUpdate();
        $zipUrl = (string) ($info['url'] ?? '');
        if ($zipUrl === '' || !str_starts_with($zipUrl, 'https://')) {
            $this->redirectToPanel($info['message'] ?? 'Kein Update.', 'panel-components-update');
        }

        $tmpZip = $this->cms->root() . '/cache/components-update.zip';
        $tmpDir = $this->cms->root() . '/cache/components-update';
        $backup = $this->cms->root() . '/cache/components-backup-' . date('YmdHis');

        $zipData = @file_get_contents($zipUrl, false, self::httpContext());
        if ($zipData === false || file_put_contents($tmpZip, $zipData) === false) {
            $this->redirectToPanel('ZIP konnte nicht geladen werden.', 'panel-components-update');
        }
        if (!self::zipMatchesManifest($zipData, (string) ($info['sha256'] ?? ''))) {
            @unlink($tmpZip);
            $this->redirectToPanel('Prüfsumme des Update-Pakets stimmt nicht mit dem Manifest überein – Update abgebrochen, nichts verändert.', 'panel-components-update');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            $this->redirectToPanel('ZIP ungültig.', 'panel-components-update');
        }

        if (is_dir($tmpDir)) {
            $this->rrmdir($tmpDir);
        }
        mkdir($tmpDir, 0775, true);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || str_contains($name, '..')) {
                $zip->close();
                $this->redirectToPanel('Unsicherer ZIP-Eintrag.', 'panel-components-update');
            }
        }
        $zip->extractTo($tmpDir);
        $zip->close();

        $newComponents = $this->findComponentsDir($tmpDir);
        if ($newComponents === null) {
            $this->redirectToPanel('Im Archiv fehlt ein /components-Ordner.', 'panel-components-update');
        }

        $components = $this->componentsDir();
        if (!@rename($components, $backup)) {
            $this->redirectToPanel('Backup konnte nicht angelegt werden, Update abgebrochen.', 'panel-components-update');
        }
        if (!@rename($newComponents, $components)) {
            $restored = @rename($backup, $components);
            $this->redirectToPanel($restored
                ? 'Components konnten nicht ersetzt werden, Backup wiederhergestellt.'
                : 'Components konnten nicht ersetzt werden. Backup liegt unter ' . basename($backup) . ', bitte manuell zurückspielen.', 'panel-components-update');
        }

        @unlink($tmpZip);
        $this->rrmdir($tmpDir);
        $this->afterCodeUpdate('components-backup-');
        $this->redirectToPanel('Components aktualisiert. Instanzdaten (JSON) unverändert.', 'panel-components-update');
    }

    /** Marker: ein Ordner mit version.json und mindestens einer Unterkomponente (schema.json). */
    private function findComponentsDir(string $dir): ?string
    {
        if (is_file($dir . '/version.json') && glob($dir . '/*/schema.json') !== []) {
            return $dir;
        }
        if (is_dir($dir . '/components') && is_file($dir . '/components/version.json')) {
            return $dir . '/components';
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $child) {
            $found = $this->findComponentsDir($child);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /** Entwickler-Werkzeug, analog buildUpdatePackage() - siehe docs/UPDATE-COMPONENTS.md. */
    private function buildComponentsUpdatePackage(): void
    {
        $version = trim((string) ($_POST['version'] ?? ''));
        $current = (string) (CMS::readJson($this->componentsDir() . '/version.json')['version'] ?? '0');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $this->redirectToPanel('Ungültige Versionsnummer (Format x.y.z erwartet).', 'panel-components-update');
        }
        if (version_compare($version, $current, '<=')) {
            $this->redirectToPanel('Neue Version muss größer als die aktuelle (' . $current . ') sein.', 'panel-components-update');
        }

        $components = $this->componentsDir();
        $checksum = $this->componentsChecksum();
        CMS::writeJson($components . '/version.json', ['version' => $version, 'released' => date('Y-m-d'), 'checksum' => $checksum]);

        $cacheDir = $this->cms->root() . '/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        $zipName = 'components-' . $version . '.zip';
        $zipPath = $cacheDir . '/' . $zipName;
        if (is_file($zipPath)) {
            unlink($zipPath);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->redirectToPanel('ZIP konnte nicht angelegt werden.', 'panel-components-update');
        }
        $this->addDirToZip($zip, $components, 'components');
        $zip->close();

        $this->redirectToPanel('Components-Update-Paket ' . $version . ' erstellt (components/version.json wurde mit hochgezählt). Noch nicht live für andere Instanzen – dafür zusätzlich ',
            'panel-components-update',
            'php dev/build-release.php ' . $version . ' --repo ludwigmair/onepage-cms-components-releases --target components',
            ' ausführen (siehe docs/UPDATE-COMPONENTS.md).');
    }

    /**
     * @return list<array{version:string,file:string,manifest:string}>
     */
    private function listComponentsUpdatePackages(): array
    {
        $cacheDir = $this->cms->root() . '/cache';
        $files = is_dir($cacheDir) ? (glob($cacheDir . '/components-*.zip') ?: []) : [];

        $out = [];
        foreach ($files as $path) {
            $name = basename($path);
            if (!preg_match('/^components-(\d+\.\d+\.\d+)\.zip$/', $name, $m)) {
                continue;
            }
            $manifest = json_encode([
                'version' => $m[1],
                'zip_url' => '<öffentliche HTTPS-URL zur hochgeladenen ' . $name . '>',
                'zip_sha256' => (string) hash_file('sha256', $path),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $out[] = ['version' => $m[1], 'file' => $name, 'manifest' => (string) $manifest];
        }
        usort($out, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $out;
    }

    private function downloadComponentsUpdatePackage(): void
    {
        $file = basename((string) ($_GET['file'] ?? ''));
        $path = $this->cms->root() . '/cache/' . $file;
        if ($file === '' || !preg_match('/^components-\d+\.\d+\.\d+\.zip$/', $file) || !is_file($path)) {
            $this->redirectToPanel('Paket nicht gefunden.', 'panel-components-update');
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    private function deleteComponentsUpdatePackage(): void
    {
        $file = basename((string) ($_POST['file'] ?? ''));
        $path = $this->cms->root() . '/cache/' . $file;
        if ($file === '' || !preg_match('/^components-\d+\.\d+\.\d+\.zip$/', $file) || !is_file($path)) {
            $this->redirectToPanel('Paket nicht gefunden.', 'panel-components-update');
        }

        unlink($path);
        $this->redirectToPanel('Paket gelöscht: ' . $file, 'panel-components-update');
    }

    private function componentsChecksum(): string
    {
        $hashes = $this->hashDir($this->componentsDir());
        ksort($hashes);
        return hash('sha256', (string) json_encode($hashes));
    }

    /** true, wenn components/ vom Stand abweicht, der beim letzten Paket-Build eingefroren wurde. */
    private function componentsModifiedSincePackage(): bool
    {
        $packaged = (string) (CMS::readJson($this->componentsDir() . '/version.json')['checksum'] ?? '');
        if ($packaged === '') {
            return false;
        }
        return $packaged !== $this->componentsChecksum();
    }

    /**
     * Fingerabdruck des aktuellen core/-Standes (ohne version.json selbst, sonst
     * wäre der Wert von sich selbst abhängig). Wird beim Paket-Bauen in
     * version.json → checksum eingefroren; renderDashboard() vergleicht live
     * dagegen, um zu zeigen, ob core/ seitdem von Hand verändert wurde (siehe
     * 'core_modified' im Template) - Antwort auf "wann muss ich ein Core-Update
     * machen".
     */
    private function coreChecksum(): string
    {
        $hashes = $this->hashDir($this->cms->root() . '/core');
        ksort($hashes);
        return hash('sha256', (string) json_encode($hashes));
    }

    /** true, wenn core/ vom Stand abweicht, der beim letzten Paket-Build eingefroren wurde (oder noch nie gebaut wurde ist kein Alarm). */
    private function coreModifiedSincePackage(): bool
    {
        $packaged = (string) (CMS::readJson($this->cms->root() . '/core/version.json')['checksum'] ?? '');
        if ($packaged === '') {
            return false;
        }
        return $packaged !== $this->coreChecksum();
    }

    /** @return array<string, string> relativer Pfad => sha256 des Dateiinhalts */
    private function hashDir(string $dir, string $base = ''): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..' || $item === '.DS_Store') {
                continue;
            }
            $path = $dir . '/' . $item;
            $rel = $base === '' ? $item : $base . '/' . $item;
            if (is_dir($path)) {
                $out += $this->hashDir($path, $rel);
            } elseif ($rel !== 'version.json') {
                $out[$rel] = (string) hash_file('sha256', $path);
            }
        }
        return $out;
    }
}
