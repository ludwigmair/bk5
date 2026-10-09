<?php

declare(strict_types=1);

namespace Core\AdminParts;

use Core\CMS;

/**
 * Teil von Core\Admin (per `use` eingebunden, läuft im Kontext von Admin –
 * $this->cms, Konstanten und alle anderen Admin-Methoden stehen zur Verfügung).
 *
 * Projekt-Instanz erstellen/angleichen und Projekt-Pakete (Theme & Daten) exportieren/importieren.
 */
trait ProjectInstanceActions
{
    /**
     * Exportiert den aktuell aktiven Projekt-Stand (content.json/config.json/
     * uploads/ + core/ + nur die tatsächlich genutzten Theme-/Component-Dateien)
     * als eigenständigen, deploybaren Ordner neben dem Projekt-Root - Antwort auf
     * die offene Frage in docs/DEPLOY.md, wie man von "lokal fertig" zu einer
     * echten Instanz kommt. Ziel ist immer project-instances/<slug>/ (fester
     * Elternordner, kein frei wählbarer Pfad) - verhindert, dass ein Formularfeld
     * beliebige Server-Pfade beschreiben kann.
     */
    private function exportProjectInstance(): void
    {
        $slug = trim((string) ($_POST['slug'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $slug)) {
            $this->redirectToPanel('Ungültiger Ordnername (nur a-z, 0-9, Bindestrich, 2-40 Zeichen).', 'panel-export-instance');
        }

        $root = $this->cms->root();
        $dest = dirname($root) . '/project-instances/' . $slug;
        if (is_dir($dest)) {
            $this->redirectToPanel("„{$slug}“ existiert bereits unter project-instances/ - anderen Namen wählen oder Ordner vorher entfernen.", 'panel-export-instance');
        }
        mkdir($dest, 0775, true);

        // Core + Grundgerüst 1:1
        $this->copyDir($root . '/core', $dest . '/core');
        foreach (['index.php', '.htaccess', 'composer.json', 'composer.lock'] as $file) {
            if (is_file($root . '/' . $file)) {
                copy($root . '/' . $file, $dest . '/' . $file);
            }
        }
        if (is_dir($root . '/vendor')) {
            $this->copyDir($root . '/vendor', $dest . '/vendor');
        }
        mkdir($dest . '/cache/twig', 0775, true);

        // Daten: aktueller Stand, aber ohne den dev-imports-Marker (rein lokal/nicht
        // deployt) - siehe Admin::switchProject().
        mkdir($dest . '/data', 0775, true);
        // content.seed.json: Einmalige Kopie für CMS::ensureSeeded(): content.json
        // selbst ist im Git-Repo-Export (siehe setUpGitWorkflow()) vom
        // Deploy-Workflow ausgeschlossen, damit spätere Live-Bearbeitungen nicht
        // überschrieben werden - beim allerersten Deploy käme dadurch aber nie ein
        // Inhalt an. content.seed.json trägt denselben Ursprungsstand, wird vom
        // Workflow NICHT ausgeschlossen (anderer Dateiname).
        // Neue Instanzen starten immer mit noindex (Staging/Baustelle soll nicht
        // in den Suchindex) - für den Livegang im Admin unter SEO ausschalten.
        $this->writeInstanceContent($dest, true);
        $config = $this->cms->config();
        unset($config['dev_import'], $config['dev_import_rev'], $config['dev_import_source']);
        CMS::writeJson($dest . '/data/config.json', $config);
        // Einmalige Kopie für CMS::ensureSeeded(): config.json selbst ist im
        // Git-Repo-Export (siehe setUpGitWorkflow()) vom Deploy-Workflow
        // ausgeschlossen, damit spätere Live-Anpassungen (Theme, Layout,
        // Update-URLs) nicht überschrieben werden - beim allerersten Deploy
        // käme dadurch aber nie eine Projekt-Konfiguration an, die Seite wäre
        // sofort kaputt. config.seed.json trägt denselben Ursprungsstand (ohne
        // dev_import-Marker und bewusst OHNE Admin-Logins - die erzeugt
        // CMS::ensureSeeded() beim ersten Boot aus .env, siehe Env/ADMIN_USERS,
        // damit in einem öffentlichen Git-Repo keine Passwort-Hashes liegen).
        $seedConfig = $config;
        $seedConfig['admin']['users'] = [];
        CMS::writeJson($dest . '/data/config.seed.json', $seedConfig);

        if (is_dir($root . '/uploads')) {
            $this->copyDir($root . '/uploads', $dest . '/uploads');
            // Gleicher Grund wie content.seed.json oben, für die
            // ursprünglichen Projektbilder: uploads/ ist ebenfalls vom Deploy
            // ausgeschlossen (schützt später live hochgeladene Bilder).
            $this->copyDir($root . '/uploads', $dest . '/uploads.seed');
        } else {
            mkdir($dest . '/uploads', 0775, true);
        }

        // themes-and-plugins/: nur was das aktive Theme + die tatsächlich
        // verwendeten Sections wirklich brauchen, nicht der komplette
        // gemeinsame Pool aller Projekte in diesem Dev-Setup.
        $theme = (string) ($config['theme'] ?? '');
        if ($theme !== '' && is_dir($root . "/themes-and-plugins/themes/{$theme}")) {
            $this->copyDir(
                $root . "/themes-and-plugins/themes/{$theme}",
                $dest . "/themes-and-plugins/themes/{$theme}"
            );
        }
        foreach (['header', 'footer', 'topbar', 'stickybar', 'cookiebanner'] as $region) {
            // Der gemeinsame Pool wird IMMER mitgenommen – er ist die alleinige
            // Quelle (CMS::renderRegion() rendert ihn bevorzugt); die eigene
            // Theme-Kopie im mitkopierten Theme-Ordner bleibt als Fallback dabei.
            $variant = $this->resolveRegionVariant($config, $region);
            if ($variant === null) {
                continue;
            }
            $poolFolder = $region . 's';
            $src = $root . "/themes-and-plugins/{$poolFolder}/{$variant}";
            if (is_dir($src)) {
                $this->copyDir($src, $dest . "/themes-and-plugins/{$poolFolder}/{$variant}");
            }
        }
        foreach ($this->usedComponentTypes() as $type) {
            $src = $root . "/themes-and-plugins/components/{$type}";
            if (is_dir($src)) {
                $this->copyDir($src, $dest . "/themes-and-plugins/components/{$type}");
            }
        }
        // version.json liegt direkt in components/, nicht in einem der oben
        // kopierten Type-Unterordner - ohne diese Kopie fehlt der neuen
        // Instanz die Grundlage für "Components prüfen"/den Versions-Badge
        // (checkComponentsUpdate() liest genau diese Datei; ohne sie fällt
        // renderDashboard() auf den Platzhalter "1.0.0" zurück).
        $componentsVersionFile = $root . '/themes-and-plugins/components/version.json';
        if (is_file($componentsVersionFile)) {
            if (!is_dir($dest . '/themes-and-plugins/components')) {
                mkdir($dest . '/themes-and-plugins/components', 0775, true);
            }
            copy($componentsVersionFile, $dest . '/themes-and-plugins/components/version.json');
        }

        $message = "Projekt-Instanz „{$slug}“ erstellt unter project-instances/{$slug}/ (composer install dort noch nötig, falls vendor/ nicht mitkam).";
        if ($this->hasDevTools() && ($_POST['git_workflow'] ?? '') === '1') {
            $gitError = $this->setUpGitWorkflow($dest);
            $message .= $gitError !== ''
                ? ' Git-Setup fehlgeschlagen: ' . $gitError
                : ' Git-Repo mit main/develop/staging angelegt (auf develop), FTP-Deploy-Workflow liegt unter .github/workflows/deploy-staging.yml bereit - im Ziel-Repo noch die GitHub-Secrets FTP_SERVER/FTP_USERNAME/FTP_PASSWORD/FTP_TARGET_DIR/BACKUP_URL/BACKUP_TOKEN (+ ADMIN_USERS) setzen.';
        }
        // Projekt ↔ Instanz verknüpfen: das geladene dev-imports-Projekt lädt künftig
        // aus dieser Instanz (aktuellster Stand), sobald sie per
        // dev/register-instance.sh <slug> registriert ist. Bestehende Verknüpfung bleibt.
        $project = (string) ($this->cms->config()['dev_import'] ?? '');
        if ($this->hasDevTools() && $project !== '' && $project !== self::EXAMPLE_PROJECT && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $project)) {
            $metaPath = dirname($root) . '/dev-imports/' . $project . '/meta.json';
            if (is_dir(dirname($metaPath))) {
                $meta = CMS::readJson($metaPath);
                if (trim((string) ($meta['instance'] ?? '')) === '') {
                    $meta['instance'] = $slug;
                    CMS::writeJson($metaPath, $meta);
                    $message .= " Projekt „{$project}“ ist jetzt mit der Instanz verknüpft – nach dev/register-instance.sh {$slug} lädt der Generator es von dort.";
                }
            }
        }
        $this->redirectToPanel($message, 'panel-export-instance');
    }

    /**
     * Registrierte Instanzen aus dev/instances.conf (Name → Port → Docroot),
     * wie dev/serve.sh sie nutzt. Rein lokale Dev-Tooling-Info – nur über
     * hasDevTools() erreichbar; deployed Instanzen haben kein dev/-Geschwister
     * und liefern hier eine leere Liste.
     *
     * @return list<array{name: string, port: int, path: string, exists: bool}>
     */
    private function devInstances(): array
    {
        $file = dirname($this->cms->root()) . '/dev/instances.conf';
        if (!is_file($file)) {
            return [];
        }
        $root = $this->cms->root();
        $instances = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            // Optionale 4. Spalte = gehostete URL (nur für dev/check-live.sh).
            if (!preg_match('/^([a-z0-9][a-z0-9-]*)\s+(\d+)\s+(\S+)(?:\s+\S+)?\s*$/', $line, $m)) {
                continue;
            }
            // Der Generator selbst (docroot = web/ = this->cms->root() synced sich
            // nie selbst an – wäre ein No-op, das nur dessen Page-Cache leert.
            // Docroot ist relativ zum Repo-Root (nicht zu web/), außer absolut.
            $docroot = $m[3];
            $path = str_starts_with($docroot, '/') ? $docroot : dirname($root) . '/' . $docroot;
            if ($path === $root) {
                continue;
            }
            $instances[] = [
                'name' => $m[1],
                'port' => (int) $m[2],
                'path' => $path,
                'exists' => is_dir($path),
            ];
        }
        return $instances;
    }

    /**
     * "Instanz angleichen": überträgt den aktuellen Stand (Inhalte, config ohne
     * dev_import-Marker, uploads/, aktives Theme + verwendete Regions/Components)
     * in eine per dev/instances.conf registrierte Instanz – core/ und vendor/
     * der Ziel-Instanz bleiben unangetastet, da nur das Webprojekt-Spezifische
     * synchronisiert wird. Docroot kommt ausschließlich
     * aus dev/instances.conf (kein frei wählbarer Pfad), der Eintrag wird per
     * Name validiert.
     */
    private function syncProjectInstance(): void
    {
        if (!$this->hasDevTools()) {
            $this->redirectToPanel('„Instanz angleichen“ gibt es nur im Generator (dev/-Tooling).', 'panel-export-instance');
            return;
        }
        $name = trim((string) ($_POST['instance'] ?? ''));
        $target = null;
        foreach ($this->devInstances() as $instance) {
            if ($instance['name'] === $name) {
                $target = $instance;
                break;
            }
        }
        if ($target === null) {
            $this->redirectToPanel('Unbekannte Instanz „' . $name . '“.', 'panel-export-instance');
            return;
        }
        $dest = $target['path'];
        if (!is_dir($dest . '/data') || !is_file($dest . '/data/content.json')) {
            $this->redirectToPanel("„{$name}“ ({$dest}) sieht nicht wie eine Instanz aus (data/content.json fehlt).", 'panel-export-instance');
            return;
        }

        $root = $this->cms->root();
        mkdir($dest . '/data', 0775, true);

        // Die noindex-Einstellung gehört zur Ziel-Instanz (Staging soll nicht durch
        // einen Sync plötzlich indexierbar werden) - nur sie bleibt erhalten.
        $targetNoindex = CMS::readJson($dest . '/data/content.json')['seo']['robots_noindex'] ?? null;
        $this->writeInstanceContent($dest, $targetNoindex === null ? null : (bool) $targetNoindex);
        $config = $this->cms->config();
        unset($config['dev_import'], $config['dev_import_rev'], $config['dev_import_source']);
        CMS::writeJson($dest . '/data/config.json', $config);
        $seedConfig = $config;
        $seedConfig['admin']['users'] = [];
        CMS::writeJson($dest . '/data/config.seed.json', $seedConfig);

        if (is_dir($root . '/uploads')) {
            $this->copyDir($root . '/uploads', $dest . '/uploads');
        }

        $theme = (string) ($config['theme'] ?? '');
        if ($theme !== '' && is_dir($root . "/themes-and-plugins/themes/{$theme}")) {
            $this->copyDir(
                $root . "/themes-and-plugins/themes/{$theme}",
                $dest . "/themes-and-plugins/themes/{$theme}"
            );
        }
        foreach (['header', 'footer', 'topbar', 'stickybar', 'cookiebanner'] as $region) {
            $variant = $this->resolveRegionVariant($config, $region);
            if ($variant === null) {
                continue;
            }
            $poolFolder = $region . 's';
            $src = $root . "/themes-and-plugins/{$poolFolder}/{$variant}";
            if (is_dir($src)) {
                $this->copyDir($src, $dest . "/themes-and-plugins/{$poolFolder}/{$variant}");
            }
        }
        foreach ($this->usedComponentTypes() as $type) {
            $src = $root . "/themes-and-plugins/components/{$type}";
            if (is_dir($src)) {
                $this->copyDir($src, $dest . "/themes-and-plugins/components/{$type}");
            }
        }
        $componentsVersionFile = $root . '/themes-and-plugins/components/version.json';
        if (is_file($componentsVersionFile)) {
            if (!is_dir($dest . '/themes-and-plugins/components')) {
                mkdir($dest . '/themes-and-plugins/components', 0775, true);
            }
            copy($componentsVersionFile, $dest . '/themes-and-plugins/components/version.json');
        }

        // Stale Page-Cache der Ziel-Instanz: Signatur-Invalidierung greift zwar von
        // selbst (content.json ändert sich), aber das Leeren macht den Sync sofort
        // sichtbar und räumt verwaiste Varianten auf.
        $this->removeDir($dest . '/cache/pages');

        $this->redirectToPanel(
            "Instanz „{$name}“ (Port {$target['port']}) angeglichen: data, uploads, Theme und verwendete Components aus dem aktuellen Generator-Stand übernommen ({$dest}). core/, vendor/ und config-Eigenheiten der Ziel-Instanz wurden nicht angefasst – die Page-Caches sind geleert.",
            'panel-export-instance'
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * "Theme-/Daten-Paket bauen": zippt content.json, config.json (ohne
     * dev_import-Marker), uploads/ (ohne generierte .optim-Varianten), aktives
     * Theme + verwendete Regions/Components und ein Manifest nach
     * cache/projekt-<stempel>.zip. Lokales Gegenstück zum Sync: das Paket kann
     * man beliebig verteilen und in jeder Instanz über "Projekt-Paket
     * importieren" einspielen – ohne dass dort dev/-Tooling oder ein gemeinsamer
     * Ordner nötig wäre.
     */
    private function buildProjectPackage(): void
    {
        $root = $this->cms->root();
        $file = 'projekt-' . date('Ymd-His') . '.zip';
        $zipPath = $root . '/cache/' . $file;
        if (is_file($zipPath)) {
            $this->redirectToPanel('Paket existiert bereits: ' . $file, 'panel-export-instance');
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->redirectToPanel('Paket konnte nicht angelegt werden (cache/ beschreibbar?).', 'panel-export-instance');
            return;
        }

        $zip->addFromString('manifest.json', (string) json_encode([
            'package' => 'project',
            'created' => date('c'),
            'theme' => (string) ($this->cms->config()['theme'] ?? ''),
            'content_sha256' => hash_file('sha256', $root . '/data/content.json'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $zip->addFile($root . '/data/content.json', 'data/content.json');
        $cleanConfig = $this->cms->config();
        unset($cleanConfig['dev_import'], $cleanConfig['dev_import_rev'], $cleanConfig['dev_import_source']);
        $zip->addFromString('data/config.json', (string) json_encode($cleanConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (is_dir($root . '/uploads')) {
            $this->addDirToZipSkipping($zip, $root . '/uploads', 'uploads', ['.optim']);
        }

        $theme = (string) ($cleanConfig['theme'] ?? '');
        if ($theme !== '' && is_dir($root . "/themes-and-plugins/themes/{$theme}")) {
            $this->addDirToZip($zip, $root . "/themes-and-plugins/themes/{$theme}", 'themes-and-plugins/themes/' . $theme);
        }
        foreach (['header', 'footer', 'topbar', 'stickybar', 'cookiebanner'] as $region) {
            $variant = $this->resolveRegionVariant($cleanConfig, $region);
            if ($variant === null) {
                continue;
            }
            $poolFolder = $region . 's';
            $src = $root . "/themes-and-plugins/{$poolFolder}/{$variant}";
            if (is_dir($src)) {
                $this->addDirToZip($zip, $src, 'themes-and-plugins/' . $poolFolder . '/' . $variant);
            }
        }
        foreach ($this->usedComponentTypes() as $type) {
            $src = $root . "/themes-and-plugins/components/{$type}";
            if (is_dir($src)) {
                $this->addDirToZip($zip, $src, 'themes-and-plugins/components/' . $type);
            }
        }
        $componentsVersionFile = $root . '/themes-and-plugins/components/version.json';
        if (is_file($componentsVersionFile)) {
            $zip->addFile($componentsVersionFile, 'themes-and-plugins/components/version.json');
        }

        $zip->close();

        $this->redirectToPanel('Theme-/Daten-Paket „' . $file . '“ erstellt unter cache/ – in einer beliebigen Instanz unter „Projekt-Paket importieren“ einspielen.', 'panel-export-instance');
    }

    /** addDirToZip-Variante mit Skip-Liste für Verzeichnisnamen (z. B. .optim). */
    private function addDirToZipSkipping(\ZipArchive $zip, string $dir, string $localBase, array $skipDirs): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..' || $item === '.DS_Store') {
                continue;
            }
            $path = $dir . '/' . $item;
            $local = $localBase . '/' . $item;
            if (is_dir($path)) {
                if (in_array($item, $skipDirs, true)) {
                    continue;
                }
                $zip->addEmptyDir($local);
                $this->addDirToZipSkipping($zip, $path, $local, $skipDirs);
            } else {
                $zip->addFile($path, $local);
            }
        }
    }

    /** Gebaute Projekt-Pakete in cache/ (projekt-<Zeitstempel>.zip), neueste zuerst. */
    private function projectPackages(): array
    {
        $cacheDir = $this->cms->root() . '/cache';
        $out = [];
        foreach ((is_dir($cacheDir) ? (glob($cacheDir . '/projekt-*.zip') ?: []) : []) as $path) {
            $name = basename((string) $path);
            if (!preg_match('/^projekt-\d{8}-\d{6}\.zip$/', $name)) {
                continue;
            }
            $manifest = null;
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $json = $zip->getFromName('manifest.json');
                $zip->close();
                $decoded = $json === false ? null : json_decode((string) $json, true);
                if (is_array($decoded)) {
                    $manifest = $decoded;
                }
            }
            $out[] = [
                'file' => $name,
                'size' => filesize($path),
                'created' => date('Y-m-d H:i', (int) filemtime($path)),
                'theme' => (string) ($manifest['theme'] ?? ''),
                'content_sha256' => (string) ($manifest['content_sha256'] ?? ''),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['file'], $a['file']));
        return $out;
    }

    private function downloadProjectPackage(): void
    {
        $file = basename((string) ($_GET['file'] ?? ''));
        $path = $this->cms->root() . '/cache/' . $file;
        if ($file === '' || !preg_match('/^projekt-\d{8}-\d{6}\.zip$/', $file) || !is_file($path)) {
            $this->redirectToPanel('Paket nicht gefunden.', 'panel-export-instance');
            return;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    private function deleteProjectPackage(): void
    {
        $file = basename((string) ($_POST['file'] ?? ''));
        $path = $this->cms->root() . '/cache/' . $file;
        if ($file === '' || !preg_match('/^projekt-\d{8}-\d{6}\.zip$/', $file) || !is_file($path)) {
            $this->redirectToPanel('Paket nicht gefunden.', 'panel-export-instance');
            return;
        }

        unlink($path);
        $this->redirectToPanel('Paket gelöscht: ' . $file, 'panel-export-instance');
    }

    /**
     * "Projekt-Paket importieren" (gilt in jeder Instanz, nicht nur im Generator):
     * nimmt ein vom Generator gebautes projekt-*.zip hoch und spielt data/,
     * uploads/ und das Theme-/Component-Subset in die Instanz ein. Bewusst robust:
     * Zip-Slip-Guard (keine .. /absolute Pfade /Backslashes), Manifest-Pflicht,
     * content.json-Prüfsumme; die Admin-Logins der Instanz (config.json →
     * admin.users) bleiben erhalten, die pflegt nur die Instanz selbst.
     */
    private function importProjectPackage(): void
    {
        $root = $this->cms->root();
        $fileField = $_FILES['package'] ?? null;
        if (!is_array($fileField) || ($fileField['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($fileField['size'] ?? 0) <= 0) {
            $this->redirectToPanel('Keine gültige Paket-Datei übermittelt.', 'panel-import-project');
            return;
        }

        $tmpDir = $root . '/cache/projekt-import-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0775, true);

        $zip = new \ZipArchive();
        if ($zip->open($fileField['tmp_name']) !== true) {
            $this->removeDir($tmpDir);
            $this->redirectToPanel('Datei ist kein gültiges ZIP-Paket.', 'panel-import-project');
            return;
        }

        $manifest = null;
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) ($zip->getNameIndex($i) ?? '');
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $clean = str_replace('\\', '/', $name);
            if ($clean !== $name || str_starts_with($clean, '/') || str_contains($clean, '../')) {
                $zip->close();
                $this->removeDir($tmpDir);
                $this->redirectToPanel('Ungültiger Eintrag im Paket: ' . $name, 'panel-import-project');
                return;
            }
            if ($clean === 'manifest.json') {
                $json = $zip->getFromIndex($i);
                $manifest = $json === false ? null : json_decode((string) $json, true);
                continue;
            }
            $allowed = false;
            foreach (['data/', 'uploads/', 'themes-and-plugins/'] as $rootPath) {
                if (str_starts_with($clean, $rootPath)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $zip->close();
                $this->removeDir($tmpDir);
                $this->redirectToPanel('Paket enthält nicht unterstützte Einträge (' . $name . ').', 'panel-import-project');
                return;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $zip->close();
                $this->removeDir($tmpDir);
                $this->redirectToPanel('Eintrag kann nicht gelesen werden: ' . $name, 'panel-import-project');
                return;
            }
            $entries[$clean] = $content;
        }
        $zip->close();

        if (!is_array($manifest) || ($manifest['package'] ?? '') !== 'project') {
            $this->removeDir($tmpDir);
            $this->redirectToPanel('Paket-Manifest fehlt oder passt nicht – das ist kein Projekt-Paket.', 'panel-import-project');
            return;
        }
        if (!isset($entries['data/content.json'])) {
            $this->removeDir($tmpDir);
            $this->redirectToPanel('Paket enthält kein data/content.json.', 'panel-import-project');
            return;
        }
        if (($manifest['content_sha256'] ?? '') !== '' && !hash_equals((string) $manifest['content_sha256'], hash('sha256', $entries['data/content.json']))) {
            $this->removeDir($tmpDir);
            $this->redirectToPanel('content.json-Prüfsumme weicht ab – Paket ist vermutlich beschädigt.', 'panel-import-project');
            return;
        }

        // Bestehende Instanz-Konfiguration merken, damit die Admin-Logins der
        // Instanz beim Import erhalten bleiben (die pflegt nur die Instanz selbst)
        // und die Update-Manifests (Update-Buttons "ausgegraut", wenn leer).
        $existing = CMS::readJson($root . '/data/config.json');
        $existingUsers = array_values($existing['admin']['users'] ?? []);
        $existingUpdate = $existing['update'] ?? [];

        $written = 0;
        foreach ($entries as $rel => $content) {
            $dest = $root . '/' . $rel;
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0775, true);
            }
            file_put_contents($dest, $content);
            $written++;
        }

        // Seeds nachziehen (ensureSeeded-Fallback) – der Import soll auch eine
        // noch nie gebootete Instanz standfest machen. config.seed.json behält
        // bewusst die leere Nutzerliste; Logins erzeugt ensureSeeded() aus .env.
        copy($root . '/data/content.json', $root . '/data/content.seed.json');
        $newConfig = CMS::readJson($root . '/data/config.json');
        $newConfig['admin']['users'] = $existingUsers;
        $newConfig['update'] = $existingUpdate;
        CMS::writeJson($root . '/data/config.json', $newConfig);
        $seedConfig = $newConfig;
        $seedConfig['admin']['users'] = [];
        CMS::writeJson($root . '/data/config.seed.json', $seedConfig);

        $this->removeDir($root . '/cache/pages');
        $this->removeDir($tmpDir);

        $this->redirectToPanel('Projekt-Paket importiert: ' . $written . ' Dateien (content.json, config.json, uploads/, Theme + verwendete Components). Admin-Logins dieser Instanz blieben erhalten, der Page-Cache wurde geleert.', 'panel-import-project');
    }

    /**
     * + einem auf dieses Projekt zugeschnittenen FTP-Deploy-Workflow (siehe
     * dev/templates/) - fürs spätere Verbinden mit einem eigenen GitHub-Repo
     * und CI-gestütztem Staging-Deploy. Legt bewusst KEIN Remote an und
     * pusht nichts - das verknüpft man selbst, wenn man so weit ist. Wird
     * nur aufgerufen, wenn hasDevTools() true ist: "git init" + Shell-
     * Aufrufe aus dem Admin heraus wollen wir nicht auf einer live
     * deployten Kunden-Instanz anbieten.
     *
     * data/config.json, data/content.json, uploads/ und cache/ bleiben
     * bewusst außerhalb des Deploy-Payloads (siehe .gitignore-Vorlage und
     * den exclude-Block im Workflow) - die werden auf dem Zielserver live
     * über den Admin-Bereich der jeweiligen Instanz gepflegt, ein
     * automatischer Deploy darf sie nie überschreiben.
     *
     * @return string leere Zeichenkette bei Erfolg, sonst eine Fehlermeldung
     */
    private function setUpGitWorkflow(string $dest): string
    {
        $templatesDir = dirname($this->cms->root()) . '/dev/templates';
        $gitignoreSrc = $templatesDir . '/instance.gitignore';
        $workflowSrc = $templatesDir . '/deploy-staging.yml';
        if (!is_file($gitignoreSrc) || !is_file($workflowSrc)) {
            return 'Vorlagen unter dev/templates/ fehlen.';
        }

        copy($gitignoreSrc, $dest . '/.gitignore');
        if (!is_dir($dest . '/.github/workflows')) {
            mkdir($dest . '/.github/workflows', 0775, true);
        }
        copy($workflowSrc, $dest . '/.github/workflows/deploy-staging.yml');
        touch($dest . '/cache/.gitkeep');
        touch($dest . '/uploads/.gitkeep');

        foreach ([
            'git init -q',
            'git symbolic-ref HEAD refs/heads/main',
            'git add -A',
            'git commit -q -m ' . escapeshellarg('Initial import'),
            'git branch develop',
            'git branch staging',
            'git checkout -q develop',
        ] as $cmd) {
            exec('cd ' . escapeshellarg($dest) . ' && ' . $cmd . ' 2>&1', $output, $code);
            if ($code !== 0) {
                return 'Abbruch bei "' . $cmd . '": ' . implode(' / ', $output);
            }
            $output = [];
        }

        return '';
    }

    /**
     * Schreibt den aktuellen Generator-Inhalt als content.json + content.seed.json
     * in eine Instanz. $noindex: true/false setzt seo.robots_noindex fest
     * ("Von Suchmaschinen ausschließen"), null übernimmt den Wert des Generators.
     */
    private function writeInstanceContent(string $dest, ?bool $noindex): void
    {
        $content = CMS::readJson($this->cms->root() . '/data/content.json');
        if ($noindex !== null) {
            $content['seo']['robots_noindex'] = $noindex;
        }
        CMS::writeJson($dest . '/data/content.json', $content);
        CMS::writeJson($dest . '/data/content.seed.json', $content);
    }

    /** Region-Varianten-Name aus config.json → layout, wie CMS::renderShell() es liest (Topbar verschachtelt, Rest flach). */
    private function resolveRegionVariant(array $config, string $region): ?string
    {
        $layout = $config['layout'] ?? [];
        if ($region === 'topbar') {
            if (!(bool) ($layout['topbar']['enabled'] ?? false)) {
                return null;
            }
            $variant = trim((string) ($layout['topbar']['theme'] ?? ''));
            return $variant !== '' ? $variant : null;
        }
        $variant = trim((string) ($layout[$region] ?? ''));
        return $variant !== '' ? $variant : null;
    }

    /** @return list<string> Distinkte Component-Types, die im aktuellen content.json wirklich verwendet werden. */
    private function usedComponentTypes(): array
    {
        $content = CMS::readJson($this->cms->root() . '/data/content.json');
        $types = [];
        foreach ($content['sections'] ?? [] as $section) {
            $type = (string) ($section['type'] ?? '');
            if ($type !== '') {
                $types[$type] = true;
            }
        }
        return array_keys($types);
    }

    private function copyDir(string $src, string $dest): void
    {
        mkdir($dest, 0775, true);
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..' || $item === '.DS_Store') {
                continue;
            }
            $from = $src . '/' . $item;
            $to = $dest . '/' . $item;
            if (is_dir($from)) {
                $this->copyDir($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }
}
