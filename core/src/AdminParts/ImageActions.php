<?php

declare(strict_types=1);

namespace Core\AdminParts;

use Core\CMS;
use Core\Image;

/**
 * Teil von Core\Admin (per `use` eingebunden, läuft im Kontext von Admin –
 * $this->cms, Konstanten und alle anderen Admin-Methoden stehen zur Verfügung).
 *
 * Bild-Uploads (inkl. SVG-Regeln), Bildverwaltung und Import externer Bilder.
 */
trait ImageActions
{
    private function handleUpload(string $fieldPath, string $fallback): string
    {
        $file = $this->nestedFile($fieldPath);
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $fallback;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? 'upload');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)) {
            return $fallback;
        }

        $dir = $this->cms->root() . '/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $target = $dir . '/' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $target) || !$this->acceptSvg($target, $ext)) {
            return $fallback;
        }
        Image::generateVariants($target);

        return 'uploads/' . basename($target);
    }

    /**
     * SVGs sind XML mit möglichem Skript-Anteil: nur Admins dürfen sie hochladen,
     * und auch dann nur gesäubert (Image::sanitizeSvg()). Abgelehnte Dateien
     * werden sofort wieder gelöscht. Andere Endungen laufen unverändert durch.
     */
    private function acceptSvg(string $path, string $ext): bool
    {
        if ($ext !== 'svg') {
            return true;
        }
        if ($this->currentIsAdmin() && Image::sanitizeSvg($path)) {
            return true;
        }
        @unlink($path);

        return false;
    }

    /** @return array<string, mixed>|null */
    private function nestedFile(string $path): ?array
    {
        if (!isset($_FILES['sections']) || !is_array($_FILES['sections'])) {
            return null;
        }
        // path like sections[id][data][image]
        if (!preg_match_all('/\[([^\]]+)\]/', $path, $m)) {
            return null;
        }
        $keys = $m[1];
        $walk = static function (array $node, array $keys, string $leaf) {
            $cur = $node[$leaf] ?? null;
            foreach ($keys as $key) {
                if (!is_array($cur) || !array_key_exists($key, $cur)) {
                    return null;
                }
                $cur = $cur[$key];
            }
            return $cur;
        };

        $name = $walk($_FILES['sections']['name'] ?? [], $keys, 'name');
        $tmp = $walk($_FILES['sections']['tmp_name'] ?? [], $keys, 'tmp_name');
        $error = $walk($_FILES['sections']['error'] ?? [], $keys, 'error');
        if (!is_string($tmp) || $tmp === '') {
            return null;
        }

        return [
            'name' => is_string($name) ? $name : 'upload',
            'tmp_name' => $tmp,
            'error' => is_int($error) ? $error : UPLOAD_ERR_NO_FILE,
        ];
    }

    private function uploadImage(): void
    {
        $ajax = ($_POST['ajax'] ?? '') === '1';

        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->failUpload($ajax, 'Kein Bild ausgewählt oder Upload fehlgeschlagen.');
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $this->failUpload($ajax, 'Dateityp nicht erlaubt (erlaubt: ' . implode(', ', $allowed) . ').');
        }

        $dir = $this->cms->root() . '/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $dir . '/' . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            $this->failUpload($ajax, 'Bild konnte nicht gespeichert werden.');
        }
        if (!$this->acceptSvg($target, $ext)) {
            $this->failUpload($ajax, $this->currentIsAdmin()
                ? 'SVG konnte nicht geprüft werden (ungültiges XML) – bitte als PNG/WebP hochladen.'
                : 'SVG-Dateien dürfen nur Admins hochladen – bitte als PNG/WebP hochladen.');
        }
        Image::generateVariants($target);

        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'path' => 'uploads/' . $name, 'name' => $name]);
            exit;
        }

        $this->redirectToPanel('Bild hochgeladen.', 'panel-images');
    }

    private function failUpload(bool $ajax, string $message): never
    {
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $message]);
            exit;
        }

        $this->redirectToPanel($message, 'panel-images');
    }

    private function deleteImage(): void
    {
        $name = basename((string) ($_POST['filename'] ?? ''));
        if ($name === '') {
            $this->redirectToPanel('Kein Bild angegeben.', 'panel-images');
        }

        $usedIn = $this->imageUsage('uploads/' . $name);
        if ($usedIn !== []) {
            $this->redirectToPanel('Bild wird noch verwendet in: ' . implode(', ', $usedIn) . ' – erst dort entfernen.', 'panel-images');
        }

        $file = $this->cms->root() . '/uploads/' . $name;
        if (is_file($file)) {
            Image::removeVariants($file);
            unlink($file);
        }
        $this->redirectToPanel('Bild gelöscht.', 'panel-images');
    }

    /**
     * Sammellöschung: mehrere Bilder aus web/uploads/ auf einmal entfernen.
     */
    private function deleteImagesMany(): void
    {
        $raw = (array) ($_POST['filenames'] ?? []);
        $names = [];
        foreach ($raw as $name) {
            $n = basename((string) $name);
            if ($n !== '' && $n !== '.' && $n !== '..') {
                $names[] = $n;
            }
        }
        $names = array_values(array_unique($names));
        if ($names === []) {
            $this->redirectToPanel('Keine Bilder ausgewählt.', 'panel-images');
        }

        $deleted = 0;
        $blocked = [];
        foreach ($names as $name) {
            $usedIn = $this->imageUsage('uploads/' . $name);
            if ($usedIn !== []) {
                $blocked[] = $name . ' (' . implode(', ', $usedIn) . ')';
                continue;
            }
            $file = $this->cms->root() . '/uploads/' . $name;
            if (is_file($file)) {
                Image::removeVariants($file);
                unlink($file);
                $deleted++;
            }
        }

        $msg = [];
        if ($deleted > 0) {
            $msg[] = $deleted . ' Bild' . ($deleted === 1 ? '' : 'er') . ' gelöscht.';
        }
        if ($blocked !== []) {
            $msg[] = 'Noch in Verwendung: ' . implode('; ', $blocked);
        }
        $this->redirectToPanel($msg !== [] ? implode(' ', $msg) : 'Keine Bilder gelöscht.', 'panel-images');
    }

    /** @return list<array{name:string, used:bool, usedIn:list<string>}> */
    private function listUploads(): array
    {
        $dir = $this->cms->root() . '/uploads';
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
        $names = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed, true)) {
                    $names[] = $entry;
                }
            }
        }
        sort($names);

        $out = [];
        foreach ($names as $name) {
            $usedIn = $this->imageUsage('uploads/' . $name);
            $out[] = ['name' => $name, 'used' => $usedIn !== [], 'usedIn' => $usedIn];
        }
        return $out;
    }

    /** @return list<string> Section-Labels, die diesen Bildpfad referenzieren (leer = ungenutzt) */
    private function imageUsage(string $path): array
    {
        $content = CMS::readJson($this->cms->root() . '/data/content.json');
        $usedIn = [];
        foreach ($content['sections'] ?? [] as $section) {
            if (is_array($section) && $this->valueContainsImage($section['data'] ?? [], $path)) {
                $usedIn[] = (string) ($section['type'] ?? '?') . ' (' . (string) ($section['id'] ?? '?') . ')';
            }
        }
        $site = $content['site'] ?? [];
        if (is_array($site) && $this->valueContainsImage($site, $path)) {
            $usedIn[] = 'Site (Logo/Favicon)';
        }
        return $usedIn;
    }

    private function valueContainsImage(mixed $data, string $path): bool
    {
        if (is_array($data)) {
            foreach ($data as $v) {
                if ($this->valueContainsImage($v, $path)) {
                    return true;
                }
            }
            return false;
        }
        return is_string($data) && $data === $path;
    }

    /**
     * Lädt alle extern (http/https) referenzierten Bilder aus den Section-Daten
     * (nur echte `type: image`-Felder, schema-geführt) herunter, speichert sie
     * in uploads/ und biegt die Referenz in content.json auf den lokalen Pfad um.
     * Gleiche URL mehrfach verwendet -> nur einmal heruntergeladen.
     */
    private function importImages(): void
    {
        $path = $this->cms->root() . '/data/content.json';
        $content = CMS::readJson($path);
        $mapping = [];

        // Wichtig: NICHT "foreach ($content['sections'] ?? [] as &$section)" – der ??-Ausdruck
        // erzeugt einen Temporärwert, &$section bindet sich dann an eine Kopie und Änderungen
        // gehen beim Zurückschreiben verloren (Downloads liefen, content.json blieb unveraendert).
        $sections = is_array($content['sections'] ?? null) ? $content['sections'] : [];
        foreach ($sections as &$section) {
            if (!is_array($section)) {
                continue;
            }
            $schema = $this->schemaFor((string) ($section['type'] ?? ''));
            $data = is_array($section['data'] ?? null) ? $section['data'] : [];
            $this->importImagesInData($schema['fields'] ?? [], $data, $mapping);
            $section['data'] = $data;
        }
        unset($section);
        $content['sections'] = $sections;

        CMS::writeJson($path, $content);
        $count = count($mapping);
        $this->redirectToPanel($count > 0 ? $count . ' Bild(er) in den Pool importiert.' : 'Keine externen Bilder gefunden.', 'panel-images');
    }

    /**
     * "Thumbnails nachziehen": erzeugt für alle vorhandenen Raster-Bilder im
     * Pool die responsiven Varianten nach (Image::generateVariants()) – für
     * Bilder, die vor dieser Funktion hochgeladen wurden. Leert anschließend
     * den Public-Seiten-Cache, weil sich dadurch srcset/width/height im
     * gerenderten HTML ändern, ohne dass content.json angefasst wird.
     */
    private function regenerateThumbnails(): void
    {
        $result = Image::generateAllVariants($this->cms->root() . '/uploads');
        CMS::clearPageCache($this->cms->root());
        if ($result['checked'] === 0) {
            $this->redirectToPanel('Keine Raster-Bilder im Pool gefunden (SVG/GIF brauchen keine Varianten).', 'panel-images');
        }
        $this->redirectToPanel("Thumbnails erzeugt/aktualisiert für {$result['generated']} von {$result['checked']} Raster-Bild(ern).", 'panel-images');
    }

    /** @param array<string, mixed> $fields @param array<string, mixed> $data @param array<string, string> $mapping */
    private function importImagesInData(array $fields, array &$data, array &$mapping): void    {
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? '');
            if ($name === '' || !array_key_exists($name, $data)) {
                continue;
            }
            if ($type === 'repeater' && is_array($data[$name])) {
                foreach ($data[$name] as &$row) {
                    if (is_array($row)) {
                        $this->importImagesInData($field['fields'] ?? [], $row, $mapping);
                    }
                }
                unset($row);
                continue;
            }
            if ($type === 'image' && is_string($data[$name]) && preg_match('#^https?://#i', $data[$name])) {
                $data[$name] = $this->importExternalImage($data[$name], $mapping);
            }
        }
    }

    /** @param array<string, string> $mapping URL -> bereits importierter lokaler Pfad (Cache innerhalb eines Laufs) */
    private function importExternalImage(string $url, array &$mapping): string
    {
        if (isset($mapping[$url])) {
            return $mapping[$url];
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $ext = 'jpg'; // z. B. Unsplash-URLs ohne Datei-Endung im Pfad
        }

        $bytes = @file_get_contents($url);
        if ($bytes === false) {
            $mapping[$url] = $url; // Download fehlgeschlagen -> Original-URL unangetastet lassen
            return $url;
        }

        $dir = $this->cms->root() . '/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = bin2hex(random_bytes(6)) . '.' . $ext;
        file_put_contents($dir . '/' . $name, $bytes);
        if (!$this->acceptSvg($dir . '/' . $name, $ext)) {
            $mapping[$url] = $url;
            return $url;
        }
        Image::generateVariants($dir . '/' . $name);

        $rel = 'uploads/' . $name;
        $mapping[$url] = $rel;
        return $rel;
    }
}
