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
     * Lokale Projekt-Übernahme für die Entwicklung (nie im Deploy): jedes
     * Unterverzeichnis von dev-imports/ (liegt außerhalb von web/, siehe
     * dev-imports/README.md) mit einer content.json gilt als übernehmbares
     * Projekt. Existiert der Ordner nicht (jede echte Installation), ist die
     * Liste leer und admin.twig blendet den ganzen Bereich aus.
     *
     * @return list<array{slug:string,label:string}>
     */
    private function scanDevImports(): array
    {
        $dir = dirname($this->cms->root()) . '/dev-imports';
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            $projectDir = $dir . '/' . $entry;
            if ($entry === '.' || $entry === '..' || !is_dir($projectDir) || !is_file($projectDir . '/content.json')) {
                continue;
            }
            $metaPath = $projectDir . '/meta.json';
            $label = is_file($metaPath) ? trim((string) (CMS::readJson($metaPath)['label'] ?? '')) : '';
            $out[] = ['slug' => $entry, 'label' => $label !== '' ? $label : $entry];
        }

        return $out;
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

        $dest = dirname($this->cms->root()) . '/dev-imports/' . $slug;
        mkdir($dest, 0775, true);

        copy($this->cms->root() . '/data/content.json', $dest . '/content.json');

        $config = $this->cms->config();
        unset($config['dev_import']);
        CMS::writeJson($dest . '/config.json', $config);

        $uploadsDst = $dest . '/uploads';
        if (is_dir($uploadsDst)) {
            $this->rrmdir($uploadsDst);
        }
        mkdir($uploadsDst, 0775, true);
        foreach (scandir($this->cms->root() . '/uploads') ?: [] as $entry) {
            $srcFile = $this->cms->root() . '/uploads/' . $entry;
            if ($entry === '.' || $entry === '..' || $entry === '.optim' || $entry[0] === '.' || !is_file($srcFile)) {
                continue;
            }
            copy($srcFile, $uploadsDst . '/' . $entry);
        }

        $metaPath = $dest . '/meta.json';
        $meta = is_file($metaPath) ? CMS::readJson($metaPath) : [];
        $existingLabel = trim((string) ($meta['label'] ?? ''));
        $siteTitle = $this->displayString($this->cms->content()['site']['title'] ?? '');
        $meta['label'] = $existingLabel !== '' ? $existingLabel : ($siteTitle !== '' ? $siteTitle : $slug);
        CMS::writeJson($metaPath, $meta);

        $this->redirectToPanel('Aktueller Stand als dev-imports/' . $slug . '/ exportiert – „Übernehmen“/„Neu einspielen“ zieht ihn nun wieder ein.', 'panel-dev-import');
    }

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

        $sourceDir = dirname($this->cms->root()) . '/dev-imports/' . $slug;
        copy($sourceDir . '/content.json', $this->cms->root() . '/data/content.json');

        // Merkt sich, welches dev-imports/-Projekt zuletzt übernommen wurde,
        // rein informativ für die "Aktiv"-Anzeige im Projekt-Import-Panel -
        // keine Laufzeit-Bedeutung sonst, CMS::boot() liest config.json wie
        // gewohnt unverändert. Läuft unabhängig davon, ob das Projekt eine
        // eigene config.json mitbringt (die ist laut Spezifikation optional) -
        // sonst bekäme ein Projekt ohne eigene config.json nie den
        // "Aktiv"-Badge.
        $configPath = $this->cms->root() . '/data/config.json';
        $configSrc = $sourceDir . '/config.json';
        $current = CMS::readJson($configPath);
        $config = is_file($configSrc) ? CMS::readJson($configSrc) : $current;
        $config['dev_import'] = $slug;
        // Die Update-Manifest-URLs sind Deploy-Infrastruktur des Generators und
        // kein Projekt-Inhalt: beim Projekt-Wechsel nie überschreiben, sonst
        // sind die Update-Buttons in dieser (und jeder danach exportierten)
        // Instanz ohne Grund ausgegraut (bl01-Fall).
        $config['update'] = $current['update'] ?? [];
        // Admin-Logins gehören ebenfalls zum Generator, nicht zum Projekt: sonst
        // bringt jedes dev-imports/<slug>/config.json seinen alten Passwort-Stand
        // mit und ein Wechsel sperrt einen ohne Vorwarnung aus (wie beim
        // Paket-Import). Nur wenn der Generator selbst (noch) keine hat, gelten
        // die des Projekts.
        $currentUsers = $current['admin']['users'] ?? [];
        if (is_array($currentUsers) && $currentUsers !== []) {
            $config['admin']['users'] = array_values($currentUsers);
        }
        CMS::writeJson($configPath, $config);

        $uploadsSrc = $sourceDir . '/uploads';
        $sourceFiles = is_dir($uploadsSrc)
            ? array_values(array_filter(scandir($uploadsSrc) ?: [], fn ($entry) => is_file($uploadsSrc . '/' . $entry)))
            : [];
        // Nur ersetzen, wenn im Projekt tatsächlich Bilder liegen – sonst
        // würde ein leerer/fehlender uploads/-Ordner web/uploads/ komplett
        // leeren, ohne etwas an dessen Stelle zu setzen (web/uploads/ ist
        // nicht Git-versioniert, ein solcher Datenverlust wäre endgültig).
        if ($sourceFiles !== []) {
            $uploadsDst = $this->cms->root() . '/uploads';
            $this->rrmdir($uploadsDst);
            mkdir($uploadsDst, 0775, true);
            foreach ($sourceFiles as $entry) {
                copy($uploadsSrc . '/' . $entry, $uploadsDst . '/' . $entry);
            }
        }

        $this->redirectToPanel('Projekt „' . $project['label'] . '“ übernommen.', 'panel-dev-import');
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
        $path = $slug !== '' ? dirname($this->cms->root()) . '/dev-imports/' . $slug . '/content.json' : '';
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
