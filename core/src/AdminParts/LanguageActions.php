<?php

declare(strict_types=1);

namespace Core\AdminParts;

use Core\CMS;

/**
 * Teil von Core\Admin (per `use` eingebunden, läuft im Kontext von Admin –
 * $this->cms, Konstanten und alle anderen Admin-Methoden stehen zur Verfügung).
 *
 * Sprachen verwalten und Übersetzungen exportieren/importieren.
 */
trait LanguageActions
{
    /**
     * {code => Anzeigename} aller wählbaren Sprachen – Basis-Namen aus dem Core
     * plus projektspezifische Ergänzungen aus config.languages_allowed (siehe
     * CMS::languageLabels()). "de" bleibt Pflicht und steht immer an erster
     * Stelle, siehe normalizeLanguages().
     */
    private function availableLanguages(): array
    {
        return CMS::languageLabels($this->cms->config());
    }

    /**
     * {code => Anzeigename} der NUR per config.languages_allowed ergänzten
     * Sprachen (ohne den festen Basis-Satz) – für die Sprachen-Verwaltung im
     * Themes-Panel (anlegen/entfernen).
     *
     * @return array<string, string>
     */
    private function extraLanguages(): array
    {
        $extra = $this->cms->config()['languages_allowed'] ?? null;
        if (!is_array($extra)) {
            return [];
        }
        if (array_is_list($extra)) {
            $map = [];
            foreach ($extra as $code) {
                $code = (string) $code;
                if ($code !== '') {
                    $map[$code] = $code;
                }
            }
            return $map;
        }
        $map = [];
        foreach ($extra as $code => $label) {
            $code = (string) $code;
            if ($code === '') {
                continue;
            }
            $map[$code] = is_string($label) && $label !== '' ? $label : $code;
        }
        return $map;
    }

    /**
     * Eigene Sprache pro Projekt anlegen (Schreibt config.languages_allowed
     * als Map {code: label}) – Basis-Sprachen des Cores sind davon unberührt.
     * Danach erscheint die Sprache in den Theme-Checkboxen und im
     * Übersetzungen-Panel; der Übersetzungs-Upload selbst läuft wie bisher
     * über das Übersetzungen-Panel.
     */
    private function languageAdd(): void
    {
        $this->assertCsrf();
        $code = trim((string) ($_POST['lang_code'] ?? ''));
        $label = trim((string) ($_POST['lang_label'] ?? ''));
        if (!preg_match('/^[a-z]{2,3}$/', $code)) {
            $this->redirectToPanel('Ungültiger Sprachcode (2–3 Kleinbuchstaben, z. B. "pl").', 'panel-theme');
        }
        if (in_array($code, CMS::AVAILABLE_LANGUAGES, true)) {
            if (in_array($code, CMS::disabledCodes($this->cms->config()), true)) {
                // Ausgeblendete Basis-Sprache im Anlege-Formular eingetippt → wieder verfügbar machen.
                $path = $this->cms->root() . '/data/config.json';
                $config = CMS::readJson($path);
                $disabled = array_values(array_filter(
                    CMS::disabledCodes($config),
                    static fn (string $c): bool => $c !== $code
                ));
                if ($disabled !== []) {
                    $config['languages_disabled'] = $disabled;
                } else {
                    unset($config['languages_disabled']);
                }
                CMS::writeJson($path, $config);
                $this->redirectToPanel('Sprache „' . $code . '“ ist wieder verfügbar – oben zur Aktivierung auswählen.', 'panel-theme');
            }
            $this->redirectToPanel('„' . $code . '“ ist Teil des Basis-Sprachsatzes.', 'panel-theme');
        }

        $path = $this->cms->root() . '/data/config.json';
        $config = CMS::readJson($path);
        $map = $this->extraLanguages();
        if (isset($map[$code])) {
            $this->redirectToPanel('Sprache „' . $code . '“ ist bereits angelegt.', 'panel-theme');
        }
        $config['languages_allowed'] = [...$map, $code => $label !== '' ? $label : $code];
        CMS::writeJson($path, $config);

        $this->redirectToPanel('Sprache „' . $code . ($label !== '' ? ' (' . $label . ')' : '') . '“ angelegt – oben zur Aktivierung verfügbar, Übersetzungen im „Übersetzungen“-Panel.', 'panel-theme');
    }

    /**
     * Sprache entfernen: eigene (config.languages_allowed) werden gelöscht,
     * unnötige Basis-Sprachen (außer "de") werden per config.languages_disabled
     * für dieses Projekt ausgeblendet. Beides bereinigt die aktive
     * config.languages-Liste; Inhalte (Tekte/Übersetzungen) bleiben erhalten
     * und fallen auf Deutsch zurück.
     */
    private function languageRemove(): void
    {
        $this->assertCsrf();
        $code = trim((string) ($_POST['lang_code'] ?? ''));
        if ($code === '' || $code === 'de') {
            $this->redirectToPanel('„de“ ist Pflicht- und Fallback-Sprache und kann nicht entfernt werden.', 'panel-theme');
        }

        $path = $this->cms->root() . '/data/config.json';
        $config = CMS::readJson($path);
        $map = $this->extraLanguages();

        if (isset($map[$code])) {
            unset($map[$code], $config['languages_allowed']);
            if ($map !== []) {
                $config['languages_allowed'] = $map;
            }
            $msg = 'Sprache „' . $code . '“ entfernt – Texte im Inhalt bleiben erhalten und fallen auf Deutsch zurück.';
        } elseif (in_array($code, CMS::AVAILABLE_LANGUAGES, true)) {
            $config['languages_disabled'] = array_values(array_unique(array_merge(CMS::disabledCodes($config), [$code])));
            $msg = 'Sprache „' . $code . '“ ausgeblendet – sie verschwindet aus Auswahl und Übersetzungen, vorhandene Texte bleiben erhalten und fallen auf Deutsch zurück.';
        } else {
            $this->redirectToPanel('Sprache „' . $code . '“ ist nicht angelegt.', 'panel-theme');
        }

        if (isset($config['languages']) && is_array($config['languages'])) {
            $config['languages'] = array_values(array_filter(
                array_map('strval', $config['languages']),
                static fn (string $l): bool => $l !== $code
            ));
        }
        CMS::writeJson($path, $config);

        $this->redirectToPanel($msg, 'panel-theme');
    }

    /** Ausgeblendete Basis-Sprache wieder verfügbar machen (config.languages_disabled bereinigen). */
    private function languageEnable(): void
    {
        $this->assertCsrf();
        $code = trim((string) ($_POST['lang_code'] ?? ''));
        $disabled = CMS::disabledCodes($this->cms->config());
        if ($code === '' || !in_array($code, $disabled, true)) {
            $this->redirectToPanel('Sprache „' . $code . '“ ist nicht ausgeblendet.', 'panel-theme');
        }

        $path = $this->cms->root() . '/data/config.json';
        $config = CMS::readJson($path);
        $disabled = array_values(array_filter(
            CMS::disabledCodes($config),
            static fn (string $c): bool => $c !== $code
        ));
        if ($disabled !== []) {
            $config['languages_disabled'] = $disabled;
        } else {
            unset($config['languages_disabled']);
        }
        CMS::writeJson($path, $config);

        $this->redirectToPanel('Sprache „' . $code . '“ ist wieder verfügbar – oben zur Aktivierung auswählen.', 'panel-theme');
    }

    /**
     * {code => Anzeigename} der per config.languages_disabled ausgeblendeten
     * Basis-Sprachen – für die Rücksprung-Chips im „Sprachen verwalten“-Panel.
     */
    private function disabledLanguages(): array
    {
        $config = $this->cms->config();
        $all = CMS::languageLabels(['languages_allowed' => $config['languages_allowed'] ?? null]);

        return array_intersect_key($all, array_flip(CMS::disabledCodes($config)));
    }

    /** Validen Sprachcode liefern (Prüfung gegen die wählbaren Sprachschlüssel). */
    private function pickAdminLang(string $lang): string
    {
        if ($lang !== '' && array_key_exists($lang, $this->availableLanguages())) {
            return $lang;
        }

        $langs = CMS::activeLanguages($this->cms->config());

        return $langs[0] ?? 'de';
    }

    /**
     * Mehrsprachige Inhalte als flache Key->Wert-Datei exportieren (eine Zeile
     * je übersetzbarem Feld, leere Werte = noch nicht übersetzt). Die Datei
     * wird extern übersetzt und über importTranslations() wieder hochgeladen –
     * es wird nur map[<lang>] gesetzt, die Struktur von content.json nie
     * verändert (gleiche Heuristik wie dev/translate.php).
     */
    private function exportTranslations(): void
    {
        $lang = (string) ($_GET['lang'] ?? '');
        if (!in_array($lang, $this->translationActiveCodes(), true)) {
            $this->redirectToPanel('Ungültige Sprache: ' . $lang, 'panel-translations');
            return;
        }

        $maps = [];
        $seen = [];
        $this->translationFlattenMaps(
            CMS::readJson($this->cms->root() . '/data/content.json'),
            '',
            CMS::availableLanguages($this->cms->config()),
            $seen,
            $maps
        );
        ksort($maps, SORT_STRING);

        $rows = [];
        foreach ($maps as $path => $map) {
            $value = $map[$lang] ?? '';
            $rows[$path] = is_array($value) ? '' : (string) $value;
        }

        $body = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="translations-' . $lang . '.json"');
        header('Content-Length: ' . (string) strlen($body));
        echo $body;
        exit;
    }

    /** Hochgeladene flache Übersetzungsdatei auf die gewählte Sprache anwenden. */
    private function importTranslations(): void
    {
        $lang = (string) ($_POST['lang'] ?? '');
        if (!in_array($lang, $this->translationActiveCodes(), true)) {
            $this->redirectToPanel('Ungültige Sprache: ' . $lang, 'panel-translations');
            return;
        }

        $fileField = $_FILES['translation_file'] ?? null;
        if (!is_array($fileField) || ($fileField['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $fileField['size'] > 5 * 1024 * 1024) {
            $this->redirectToPanel('Keine gültige Übersetzungsdatei übermittelt (JSON, max. 5 MB).', 'panel-translations');
            return;
        }

        $raw = (string) file_get_contents((string) $fileField['tmp_name']);
        $rows = json_decode($raw, true);
        if (!is_array($rows)) {
            $this->redirectToPanel('Datei ist keine gültige JSON-Übersetzungsdatei (flache Key->Wert-Struktur erwartet).', 'panel-translations');
            return;
        }

        $root = $this->cms->root();
        $content = CMS::readJson($root . '/data/content.json');
        $this->pushHistory($content);

        $applied = 0;
        $skipped = 0;
        $ignored = 0;
        $missing = [];
        foreach ($rows as $path => $value) {
            $path = (string) $path;
            $tokens = explode('.', $path);
            if ($value === null) {
                $ignored++;
                continue;
            }
            if (is_array($value)) {
                $skipped++;
                $missing[] = $path . ' (Wert ist ein Objekt)';
                continue;
            }
            if ($this->translationApplyKey($content, $tokens, $lang, (string) $value)) {
                $applied++;
            } else {
                $skipped++;
                $missing[] = $path . ' (unerkannt – Struktur/Sprachcodes geändert?)';
            }
        }

        CMS::writeJson($root . '/data/content.json', $content);
        CMS::clearPageCache($root);

        $message = $applied . ' Keys übernommen (Sprache: ' . $lang . ').';
        if ($ignored > 0) {
            $message .= ' ' . $ignored . ' null-Werte ignoriert.';
        }
        if ($missing !== []) {
            $show = array_slice($missing, 0, 5);
            $tail = count($missing) > count($show) ? '; …' : '';
            $message .= ' Übersprungen: ' . count($missing) . ' (' . implode('; ', $show) . $tail . ').';
        }
        $this->redirectToPanel($message, 'panel-translations');
    }

    /** Aktive Sprachcodes (config.languages, als Liste oder Map) – mindestens ["de"]. */
    private function translationActiveCodes(): array
    {
        $languages = $this->cms->config()['languages'] ?? ['de'];
        if (!is_array($languages) || $languages === []) {
            return ['de'];
        }
        $codes = array_is_list($languages) ? $languages : array_keys($languages);
        $out = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            if ($code !== '') {
                $out[] = $code;
            }
        }
        return $out !== [] ? $out : ['de'];
    }

    /** Liste aller Sprach-Maps in content.json flach aufsammeln (Key = Pfad). */
    private function translationFlattenMaps(array $node, string $path, array $available, array &$seenIds, array &$out): void
    {
        $keys = array_keys($node);
        if (!array_is_list($node) && $keys !== [] && array_diff($keys, $available) === []) {
            $out[$path] = $node;
            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $i => $item) {
                if (is_array($item)) {
                    $id = $item['id'] ?? null;
                    $id = is_string($id) ? trim($id) : '';
                    if ($id !== '' && !isset($seenIds[$id])) {
                        $seenIds[$id] = true;
                        $this->translationFlattenMaps($item, $path . '[' . $id . ']', $available, $seenIds, $out);
                        continue;
                    }
                }
                $this->translationFlattenMaps($item, $path . '[' . $i . ']', $available, $seenIds, $out);
            }
            return;
        }
        foreach ($node as $k => $v) {
            if (is_string($k) && $k !== '' && is_array($v)) {
                $this->translationFlattenMaps($v, $path === '' ? $k : $path . '.' . $k, $available, $seenIds, $out);
            }
        }
    }

    private function translationParseToken(string $token): array
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $token, $m)) {
            return [$m[0], null];
        }
        if (preg_match('/^([A-Za-z0-9_]+)\[([^\]]+)\]$/', $token, $m)) {
            return [$m[1], $m[2]];
        }
        return [null, null];
    }

    /** Leerer Wert auf eine Map ohne diesen Sprachschlüssel ist No-op (Fallback auf erste aktive Sprache). */
    private function translationSetOrSkip(array &$map, string $lang, string $value): void
    {
        if ($value === '' && !array_key_exists($lang, $map)) {
            return;
        }
        $map[$lang] = $value;
    }

    private function translationApplyKey(array &$node, array $tokens, string $lang, string $value): bool
    {
        $token = array_shift($tokens) ?? '';
        if ($token === '') {
            return false;
        }
        [$key, $member] = $this->translationParseToken($token);
        if ($key === null || !is_array($node[$key] ?? null)) {
            return false;
        }
        if ($member === null) {
            if ($tokens === []) {
                if (array_is_list($node[$key])) {
                    return false;
                }
                $this->translationSetOrSkip($node[$key], $lang, $value);
                return true;
            }
            return $this->translationApplyKey($node[$key], $tokens, $lang, $value);
        }
        if (array_is_list($node[$key])) {
            if (is_numeric($member)) {
                $idx = (int) $member;
                if (!array_key_exists($idx, $node[$key]) || !is_array($node[$key][$idx])) {
                    return false;
                }
            } else {
                $idx = null;
                foreach ($node[$key] as $i => $item) {
                    if (is_array($item) && ($item['id'] ?? null) === $member) {
                        $idx = $i;
                        break;
                    }
                }
                if ($idx === null) {
                    return false;
                }
            }
            if ($tokens === []) {
                if (array_is_list($node[$key][$idx])) {
                    return false;
                }
                $this->translationSetOrSkip($node[$key][$idx], $lang, $value);
                return true;
            }
            return $this->translationApplyKey($node[$key][$idx], $tokens, $lang, $value);
        }
        if (!array_key_exists($member, $node[$key])) {
            return false;
        }
        if ($tokens === []) {
            if (!is_array($node[$key][$member]) || array_is_list($node[$key][$member])) {
                return false;
            }
            $this->translationSetOrSkip($node[$key][$member], $lang, $value);
            return true;
        }
        return $this->translationApplyKey($node[$key][$member], $tokens, $lang, $value);
    }

    /**
     * Passt config.languages_disabled an den Sprachsatz eines Themes an:
     * Basis-Sprachen, die das Theme mitbringt, werden wieder verfügbar,
     * Basis-Sprachen, die es nicht mitbringt, werden ausgeblendet. "de" ist
     * nie ausblendbar. Projekteigene Ergänzungen aus config.languages_allowed
     * bleiben unberührt (die sind projekt-, nicht theme-spezifisch). Liefert
     * $config mit aktualisiertem languages_disabled zurück – Aufrufer müssen
     * danach normalizeLanguages() gegen DIESEN Stand laufen lassen (siehe dort).
     *
     * @param list<string> $themeLangs
     */
    private function syncThemeLanguages(array $themeLangs, array $config): array
    {
        $disabled = CMS::disabledCodes($config);
        foreach (CMS::AVAILABLE_LANGUAGES as $code) {
            if ($code === 'de') {
                continue;
            }
            if (in_array($code, $themeLangs, true)) {
                $disabled = array_values(array_filter(
                    $disabled,
                    static fn (string $c): bool => $c !== $code
                ));
            } elseif (!in_array($code, $disabled, true)) {
                $disabled[] = $code;
            }
        }
        if ($disabled !== []) {
            $config['languages_disabled'] = $disabled;
        } else {
            unset($config['languages_disabled']);
        }

        return $config;
    }

    /**
     * "de" ist immer Pflicht und steht immer an erster Stelle (= Default-/
     * Fallback-Sprache, siehe CMS::detectLocale()/localizeContent()). Reduziert
     * sich die Auswahl auf nur "de", verschwindet der Sprach-Umschalter im
     * Admin wieder und jedes Feld zeigt ein einzelnes Eingabefeld statt der
     * Sprach-Tabs – nichts geht dabei verloren, die Sprach-Maps in
     * content.json bleiben einfach ungenutzt liegen.
     *
     * $config für Aufrufer, die config.json vorher selbst verändert haben
     * (z. B. applyTheme, das languages_disabled aufhebt): Die Validierung
     * muss gegen den NEUEN Stand laufen, nicht gegen den Boot-Snapshot.
     */
    private function normalizeLanguages(mixed $selected, ?array $config = null): array
    {
        $selected = is_array($selected) ? array_map('strval', $selected) : [];
        $labels = $config !== null ? CMS::languageLabels($config) : $this->availableLanguages();
        $selected = array_values(array_intersect($selected, array_keys($labels)));

        return array_values(array_unique(array_merge(['de'], $selected)));
    }
}
