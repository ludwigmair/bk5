<?php

declare(strict_types=1);

namespace Core\AdminParts;

use Core\CMS;

/**
 * Teil von Core\Admin (per `use` eingebunden, läuft im Kontext von Admin –
 * $this->cms, Konstanten und alle anderen Admin-Methoden stehen zur Verfügung).
 *
 * Lokaler Projekt-Import (dev-imports/) und Bearbeitungsverlauf (Sicherung).
 */
trait DevImportActions
{
    /**
     * Projekte für den Generator (nur mit Dev-Tools): jedes dev-imports/<slug>/
     * plus das Beispiel-Projekt (dev/example-project/). Je Projekt die Datenquelle
     * beim Laden – in dieser Reihenfolge: lokale Instanz (meta.json → "instance",
     * Name aus dev/instances.conf; dort liegt der aktuellste Inhalt), sonst
     * dev-imports/<slug>/, beim Beispiel-Projekt dessen Ordner – sowie das
     * zugewiesene Theme (config.json der Quelle → theme, sonst meta.json → theme).
     *
     * @return list<array{slug:string,label:string,theme:string,instance:string,source:string,source_label:string,dir:string,languages:list<string>}>
     */
    private function scanDevImports(): array
    {
        $base = dirname($this->cms->root());
        $instances = [];
        foreach ($this->devInstances() as $inst) {
            $instances[$inst['name']] = $inst;
        }

        $candidates = [];
        if (is_dir($base . '/dev-imports')) {
            foreach (scandir($base . '/dev-imports') ?: [] as $entry) {
                $dir = $base . '/dev-imports/' . $entry;
                if ($entry !== '.' && $entry !== '..' && is_dir($dir) && is_file($dir . '/content.json')) {
                    $candidates[$entry] = $dir;
                }
            }
        }
        if (is_file($base . '/dev/example-project/content.json')) {
            $candidates[self::EXAMPLE_PROJECT] = $base . '/dev/example-project';
        }

        $out = [];
        foreach ($candidates as $slug => $dir) {
            $meta = CMS::readJson($dir . '/meta.json');
            $instanceName = trim((string) ($meta['instance'] ?? ''));
            $instance = $instances[$instanceName] ?? null;
            if ($instance !== null && $instance['exists'] && is_file($instance['path'] . '/data/content.json')) {
                $source = 'instanz';
                $sourceLabel = 'lokale Instanz ' . $instanceName;
                $dataDir = $instance['path'] . '/data';
                $uploadsDir = $instance['path'] . '/uploads';
            } else {
                $source = $slug === self::EXAMPLE_PROJECT ? 'beispiel' : 'dev-imports';
                $sourceLabel = $source === 'beispiel' ? 'Beispiel-Datensatz' : 'dev-imports/' . $slug;
                $dataDir = $dir;
                $uploadsDir = $dir . '/uploads';
            }
            $config = CMS::readJson($dataDir . '/config.json');
            $label = trim((string) ($meta['label'] ?? ''));
            $out[] = [
                'slug' => $slug,
                'label' => $label !== '' ? $label : $slug,
                'theme' => (string) ($config['theme'] ?? ($meta['theme'] ?? '')),
                'instance' => $instance !== null ? $instanceName : '',
                'source' => $source,
                'source_label' => $sourceLabel,
                'dir' => $dir,
                'data_dir' => $dataDir,
                'uploads_dir' => $uploadsDir,
                'languages' => array_values(array_map('strval', (array) ($config['languages'] ?? ['de']))),
            ];
        }
        // Beispiel-Projekt ans Ende
        usort($out, static fn ($a, $b) => [$a['slug'] === self::EXAMPLE_PROJECT, $a['label']] <=> [$b['slug'] === self::EXAMPLE_PROJECT, $b['label']]);

        return $out;
    }

    /** Prüfsumme des aktuellen Generator-Inhalts – Grundlage für "ungesicherte Änderungen?" beim Projektwechsel. */
    private function generatorContentRev(): string
    {
        return (string) @hash_file('sha256', $this->cms->root() . '/data/content.json');
    }

    /** Hat sich der Generator-Inhalt seit dem Laden des aktiven Projekts verändert? */
    private function generatorIsDirty(): bool
    {
        $loadedRev = (string) (CMS::readJson($this->cms->root() . '/data/config.json')['dev_import_rev'] ?? '');

        return $loadedRev !== '' && !hash_equals($loadedRev, $this->generatorContentRev());
    }

    /**
     * Sichert den aktuellen Generator-Stand (content.json, config.json ohne lokale
     * Marker, uploads/ ohne .optim) in den Ordner des aktiven Projekts:
     * dev-imports/<slug>/ bzw. dev/example-project/. Bewusst nicht in eine lokale
     * Instanz – dorthin geht Inhalt nur ausdrücklich per „Instanz angleichen“.
     */
    private function saveGeneratorToProject(string $slug): string
    {
        $base = dirname($this->cms->root());
        $dest = $slug === self::EXAMPLE_PROJECT ? $base . '/dev/example-project' : $base . '/dev-imports/' . $slug;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            return '';
        }
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        copy($this->cms->root() . '/data/content.json', $dest . '/content.json');
        $config = CMS::readJson($this->cms->root() . '/data/config.json');
        unset($config['dev_import'], $config['dev_import_rev'], $config['dev_import_source'], $config['admin'], $config['update']);
        CMS::writeJson($dest . '/config.json', $config);
        $uploadsDst = $dest . '/uploads';
        if (is_dir($uploadsDst)) {
            $this->rrmdir($uploadsDst);
        }
        mkdir($uploadsDst, 0775, true);
        foreach (scandir($this->cms->root() . '/uploads') ?: [] as $entry) {
            $src = $this->cms->root() . '/uploads/' . $entry;
            if ($entry[0] !== '.' && is_file($src)) {
                copy($src, $uploadsDst . '/' . $entry);
            }
        }

        return substr($dest, strlen($base) + 1);
    }
    /**
     * Kopiert content.json/config.json/uploads/ eines dev-imports/-Projekts
     * 1:1 in dieses CMS: vollständige Übernahme, kein Merge. Enthält der
     * Projekt-Ordner mindestens ein Bild in uploads/, ersetzt das
     * web/uploads/ komplett (statt nur zu ergänzen), damit keine Bilder
     * eines vorher aktiven Projekts/Demo-Contents liegen bleiben. Ein
     * leerer oder fehlender uploads/-Ordner löst dagegen KEINEN Wipe aus –
     * web/uploads/ ist nicht Git-versioniert, ein Leeren ohne Ersatz wäre
     * endgültiger Datenverlust.
     */
    /**
     * Lokaler Export des AKTUELLEN aktiven Stands in einen bestehenden
     * dev-imports/-Eintrag (Überschreiben des dortigen Snapshots) – Gegenstück
     * zu switchProject(), damit ein Projekt – gerade das aktive – nach
     * Änderungen erneut exportiert und so jederzeit re-importiert werden kann.
     * Nur für Slugs, die scanDevImports() bereits kennt (kein beliebiger
     * Zielordner). uploads/ wird ohne generierte .optim-Varianten kopiert;
     * config.json erhält bewusst keinen dev_import-Marker (rein lokale
     * Information, der Snapshot soll davon frei bleiben – der Marker entsteht
     * erst wieder durch switchProject()).
     */
    private function exportDevImport(): void
    {
        $slug = trim((string) ($_POST['project'] ?? ''));
        $project = null;
        foreach ($this->scanDevImports() as $candidate) {
            if ($candidate['slug'] === $slug) {
                $project = $candidate;
                break;
            }
        }
        if ($project === null) {
            $this->redirectToPanel('Projekt nicht gefunden – lokaler Export nur in bestehende dev-imports/-Einträge.', 'panel-dev-import');
        }

        $saved = $this->saveGeneratorToProject($slug);
        // Gehört der Stand zum aktiven Projekt, gilt er jetzt als gesichert.
        $configPath = $this->cms->root() . '/data/config.json';
        $config = CMS::readJson($configPath);
        if (($config['dev_import'] ?? '') === $slug) {
            $config['dev_import_rev'] = $this->generatorContentRev();
            CMS::writeJson($configPath, $config);
        }
        $metaPath = $project['dir'] . '/meta.json';
        $meta = CMS::readJson($metaPath);
        if (trim((string) ($meta['label'] ?? '')) === '') {
            $siteTitle = $this->displayString($this->cms->content()['site']['title'] ?? '');
            $meta['label'] = $siteTitle !== '' ? $siteTitle : $slug;
            CMS::writeJson($metaPath, $meta);
        }

        $this->redirectToPanel('Aktueller Stand gesichert nach ' . $saved . '/.', 'panel-dev-import');
    }

    /**
     * Projekt laden = Daten + zugewiesenes Theme. Quelle siehe scanDevImports()
     * (lokale Instanz → dev-imports → Beispiel). Ungesicherte Änderungen am
     * Generator-Stand werden nie still überschrieben: ohne Entscheidung
     * (dirty_action = save|discard) gibt es eine Rückfrage im Panel.
     *
     * Aus der Generator-Config bleiben erhalten: Admin-Logins und Update-URLs
     * (Infrastruktur des Generators, kein Projekt-Inhalt). Die Marker dev_import,
     * dev_import_source und dev_import_rev sind rein lokal.
     */
    private function switchProject(): void
    {
        $slug = trim((string) ($_POST['project'] ?? ''));
        $project = null;
        foreach ($this->scanDevImports() as $candidate) {
            if ($candidate['slug'] === $slug) {
                $project = $candidate;
                break;
            }
        }
        if ($project === null) {
            $this->redirectToPanel('Projekt nicht gefunden.', 'panel-dev-import');
        }

        $configPath = $this->cms->root() . '/data/config.json';
        $current = CMS::readJson($configPath);
        $currentSlug = (string) ($current['dev_import'] ?? '');
        $action = (string) ($_POST['dirty_action'] ?? '');
        $saved = '';
        if ($this->generatorIsDirty()) {
            if ($action === 'save' && $currentSlug !== '') {
                $saved = $this->saveGeneratorToProject($currentSlug);
            } elseif ($action !== 'discard') {
                $_SESSION['pending_project_switch'] = $slug;
                $this->redirectToPanel('Der aktuelle Stand von „' . $currentSlug . '“ wurde seit dem Laden geändert – sichern oder verwerfen?', 'panel-dev-import');
            }
        }
        unset($_SESSION['pending_project_switch'], $_SESSION['theme_preview']);

        $dataDir = $project['data_dir'];
        copy($dataDir . '/content.json', $this->cms->root() . '/data/content.json');

        $config = is_file($dataDir . '/config.json') ? CMS::readJson($dataDir . '/config.json') : $current;
        // Das Theme des Projekts gilt immer (aus dessen config.json, sonst meta.json) –
        // auch wenn die Quelle keine eigene config.json hat und die aktuelle
        // Generator-Config als Basis dient.
        if ($project['theme'] !== '') {
            $config['theme'] = $project['theme'];
        }
        $config['update'] = $current['update'] ?? [];
        $currentUsers = $current['admin']['users'] ?? [];
        if (is_array($currentUsers) && $currentUsers !== []) {
            $config['admin']['users'] = array_values($currentUsers);
        }
        $config['dev_import'] = $slug;
        $config['dev_import_source'] = $project['source'];
        $config['dev_import_rev'] = $this->generatorContentRev();
        CMS::writeJson($configPath, $config);

        // uploads/ nur ersetzen, wenn die Quelle Bilder mitbringt (web/uploads/ ist
        // nicht versioniert – ein Leeren ohne Ersatz wäre endgültig).
        $uploadsSrc = $project['uploads_dir'];
        $sourceFiles = is_dir($uploadsSrc)
            ? array_values(array_filter(scandir($uploadsSrc) ?: [], fn ($e) => $e[0] !== '.' && is_file($uploadsSrc . '/' . $e)))
            : [];
        if ($sourceFiles !== []) {
            $uploadsDst = $this->cms->root() . '/uploads';
            $this->rrmdir($uploadsDst);
            mkdir($uploadsDst, 0775, true);
            foreach ($sourceFiles as $entry) {
                copy($uploadsSrc . '/' . $entry, $uploadsDst . '/' . $entry);
            }
        }
        $this->rrmdir($this->cms->root() . '/cache/pages');

        $this->redirectToPanel('Projekt „' . $project['label'] . '“ geladen (Daten aus ' . $project['source_label']
            . ', Theme ' . ($config['theme'] ?? '–') . ').' . ($saved !== '' ? ' Vorheriger Stand gesichert nach ' . $saved . '/.' : ''), 'panel-dev-import');
    }
    private function historyDir(): string
    {
        return $this->cms->root() . '/data/.history';
    }

    /**
     * Legt vor jeder inhaltlichen Änderung eine volle Kopie des bisherigen
     * content.json als Verlaufs-Einträg an (max. 3, älteste fliegt raus) –
     * die Grundlage für "Bearbeitungsstand wiederherstellen". Bewusst eine
     * volle Kopie (auch Bilder): die Auswahl "nur Text" passiert erst beim
     * Wiederherstellen (mergeTextInto()), nicht schon beim Sichern.
     */
    private function pushHistory(array $content): void
    {
        $dir = $this->historyDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir . '/' . str_replace('.', '-', (string) microtime(true)) . '.json';
        CMS::writeJson($file, $content);

        $files = glob($dir . '/*.json') ?: [];
        rsort($files); // neueste zuerst (Dateiname = Zeitstempel)
        foreach (array_slice($files, 3) as $stale) {
            unlink($stale);
        }
    }

    /** @return list<array{file: string, label: string}> */
    private function listHistory(): array
    {
        $dir = $this->historyDir();
        $files = is_dir($dir) ? (glob($dir . '/*.json') ?: []) : [];
        rsort($files); // neueste zuerst

        $out = [];
        foreach (array_slice($files, 0, 3) as $path) {
            $ts = (float) str_replace('-', '.', basename($path, '.json'));
            $out[] = ['file' => basename($path), 'label' => date('d.m.Y H:i:s', (int) $ts)];
        }
        return $out;
    }

    /**
     * Setzt Text-Inhalte (Sections + Stammdaten) aus $source in die aktuelle
     * content.json zurück – Bild-Felder (schema-geführt "image") bleiben
     * unangetastet, ebenso alles, was in $source keine Entsprechung hat
     * (neue Sections seit dem Quellstand, Nav, Labels, Theme/Brand-Config).
     * Sections werden über ihre id gematcht, nicht über die Position.
     */
    private function mergeTextInto(array $source): void
    {
        $path = $this->cms->root() . '/data/content.json';
        $content = CMS::readJson($path);

        foreach (['site' => ['title', 'tagline', 'logo_text']] as $key => $fields) {
            foreach ($fields as $field) {
                if (isset($source[$key][$field])) {
                    $content[$key][$field] = $source[$key][$field];
                }
            }
        }
        foreach (['name', 'owner', 'street', 'zip', 'city', 'phone', 'email', 'vat_id', 'register', 'hours', 'maps_query'] as $field) {
            if (isset($source['business'][$field])) {
                $content['business'][$field] = $source['business'][$field];
            }
        }
        foreach (['title', 'description', 'og_title', 'og_description'] as $field) {
            if (isset($source['seo'][$field])) {
                $content['seo'][$field] = $source['seo'][$field];
            }
        }
        foreach (['impressum', 'datenschutz'] as $key) {
            if (isset($source['legal'][$key]['blocks'])) {
                $content['legal'][$key]['blocks'] = $source['legal'][$key]['blocks'];
            } elseif (isset($source['legal'][$key]['body'])) {
                // Altes Sicherungs-/Import-Snapshot von vor der Umstellung auf
                // "blocks" - renderLegal() fängt das per Fallback ab.
                $content['legal'][$key]['body'] = $source['legal'][$key]['body'];
            }
        }

        $sourceById = [];
        foreach ($source['sections'] ?? [] as $section) {
            if (is_array($section) && isset($section['id'])) {
                $sourceById[(string) $section['id']] = $section;
            }
        }
        foreach ($content['sections'] ?? [] as $i => $section) {
            if (!is_array($section)) {
                continue;
            }
            $match = $sourceById[(string) ($section['id'] ?? '')] ?? null;
            if ($match === null || ($match['type'] ?? '') !== ($section['type'] ?? '')) {
                continue;
            }
            $schema = $this->schemaFor((string) ($section['type'] ?? ''));
            $data = is_array($section['data'] ?? null) ? $section['data'] : [];
            $this->mergeTextInData($schema['fields'] ?? [], $match['data'] ?? [], $data);
            $content['sections'][$i]['data'] = $data;
        }

        CMS::writeJson($path, $content);
    }

    /** @param array<string, mixed> $fields @param array<string, mixed> $sourceData @param array<string, mixed> $data */
    private function mergeTextInData(array $fields, array $sourceData, array &$data): void
    {
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? '');
            if ($name === '' || !array_key_exists($name, $sourceData) || $type === 'image') {
                continue;
            }
            if ($type === 'repeater' && is_array($sourceData[$name]) && is_array($data[$name] ?? null)) {
                foreach ($data[$name] as $i => &$row) {
                    if (is_array($row) && is_array($sourceData[$name][$i] ?? null)) {
                        $this->mergeTextInData($field['fields'] ?? [], $sourceData[$name][$i], $row);
                    }
                }
                unset($row);
                continue;
            }
            if ($type !== 'repeater') {
                $data[$name] = $sourceData[$name];
            }
        }
    }

    private function restoreImportBaseline(): void
    {
        $slug = trim((string) ($this->cms->config()['dev_import'] ?? ''));
        $path = '';
        foreach ($this->scanDevImports() as $candidate) {
            if ($candidate['slug'] === $slug) {
                $path = $candidate['data_dir'] . '/content.json';
            }
        }
        if ($slug === '' || !is_file($path)) {
            $this->redirectToPanel('Kein aktives Import-Projekt gefunden.', 'panel-history');
        }

        $this->pushHistory(CMS::readJson($this->cms->root() . '/data/content.json'));
        $this->mergeTextInto(CMS::readJson($path));
        $this->redirectToPanel('Text-Inhalte auf Import-Stand zurückgesetzt.', 'panel-history');
    }

    private function restoreHistory(): void
    {
        $file = basename(trim((string) ($_POST['file'] ?? '')));
        $known = array_column($this->listHistory(), 'file');
        if (!in_array($file, $known, true)) {
            $this->redirectToPanel('Bearbeitungsstand nicht gefunden.', 'panel-history');
        }

        $current = CMS::readJson($this->cms->root() . '/data/content.json');
        $this->pushHistory($current);
        $this->mergeTextInto(CMS::readJson($this->historyDir() . '/' . $file));
        $this->redirectToPanel('Vorheriger Bearbeitungsstand wiederhergestellt.', 'panel-history');
    }
}
