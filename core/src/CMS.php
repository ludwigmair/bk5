<?php

declare(strict_types=1);

namespace Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class CMS
{
    /**
     * Basis-Satz aller Sprachcodes, die im Content als Übersetzungs-Map-Schlüssel
     * erkannt werden (unabhängig davon, welche Sprachen im aktiven Theme gerade
     * ausgewählt sind). Projekte können über config.languages_allowed weitere
     * Codes ergänzen und über config.languages_disabled unnötige Basis-Codes
     * ausblenden, ohne ein Core-Update zu brauchen – siehe availableLanguages().
     */
    public const AVAILABLE_LANGUAGES = ['de', 'en', 'fr', 'it', 'es', 'nl', 'lb'];

    /** Standard-Anzeigenamen je Sprachcode (Basis für languageLabels()). */
    private const DEFAULT_LANGUAGE_LABELS = [
        'de' => 'Deutsch', 'en' => 'Englisch', 'fr' => 'Französisch',
        'it' => 'Italienisch', 'es' => 'Spanisch', 'nl' => 'Niederländisch',
        'lb' => 'Lëtzebuergesch',
    ];

    /** Fertig gerenderte Public-Seiten (siehe render()/renderLegal()). */
    public const PAGE_CACHE_DIR = '/cache/pages';

    public function __construct(
        private readonly string $root,
        private readonly array $config,
        private readonly array $content,
        private readonly Environment $twig,
        private readonly ?string $locale = null,
    ) {
    }

    /**
     * @param string|null $locale Aktuelle Sprache für die Public-Seite (aus der
     *     URL, siehe detectLocale()) – löst mehrsprachige Felder
     *     ({"de": "...", "fr": "..."}) zu einem einzelnen String auf, bevor
     *     Twig irgendetwas sieht. null (Admin-Seite) lässt den Content
     *     unangetastet, damit dort alle Sprachen bearbeitbar bleiben.
     */
    public static function boot(string $root, ?string $locale = null): self
    {
        Env::load($root);
        self::ensureSeeded($root);

        $config = self::readJson($root . '/data/config.json');
        // Zeitzone für alle Zeitangaben (Admin-Anzeige, Backup-Namen, Anfragen):
        // ohne Vorgabe liefen viele Hoster (und php -S lokal) auf UTC, die Zeiten
        // im Admin waren dann 1–2 Stunden versetzt. config.json → timezone.
        $timezone = (string) ($config['timezone'] ?? 'Europe/Berlin');
        date_default_timezone_set(in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'Europe/Berlin');
        // Theme-Vorschau (Admin → Themes → „Vorschau“): nur beim Rendern der öffentlichen
        // Seite ($locale gesetzt), nur im Generator (dev/ vorhanden) und nur in dieser
        // Sitzung – config.json bleibt unberührt, der Admin sieht die echte Config.
        // Aktives Theme einer Instanz kommt mit dem Deploy (themes-and-plugins/active-theme.json,
        // vom Sync geschrieben) – nicht aus der Server-config.json, die nie deployt wird. So
        // kommt auch ein Theme-Wechsel aus dem Generator sicher live an. Im Generator gibt es
        // die Datei nicht, dort gilt config.theme.
        $deployedTheme = self::deployedTheme($root);
        if ($deployedTheme !== '') {
            $config['theme'] = $deployedTheme;
        }
        $themePreview = '';
        if ($locale !== null && !empty($_SESSION['theme_preview']) && basename($root) === 'web' && is_dir(dirname($root) . '/dev')) {
            $themePreview = (string) $_SESSION['theme_preview'];
            $config['theme'] = $themePreview;
        }
        $config = self::applyThemeToConfig($root, $config);
        $content = self::readJson($root . '/data/content.json');
        $languages = self::activeLanguages($config);
        $available = self::availableLanguages($config);

        if ($locale !== null) {
            $content = self::localizeContent($content, $locale, $languages, $available);
        }

        $cacheDir = $root . '/cache/twig';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $loader = new FilesystemLoader([
            $root . '/themes-and-plugins',
            $root . '/core/templates',
        ]);

        $twig = new Environment($loader, [
            'cache' => $cacheDir,
            'auto_reload' => true,
            'strict_variables' => false,
        ]);

        $twig->addFilter(new TwigFilter('rich', [RichText::class, 'render'], ['is_safe' => ['html']]));
        $twig->addFilter(new TwigFilter('rich_block', [RichText::class, 'renderBlock'], ['is_safe' => ['html']]));
        $twig->addFilter(new TwigFilter('slug', [self::class, 'slugify']));
        // Löst eine Sprach-Map ({"de": "…", "fr": "…"}) für die aktuelle Sprache
        // zu einem String auf – dieselbe Logik wie localizeContent(), aber als
        // Filter auch für Admin-Ansichten, die mit den rohen (unaufgelösten)
        // Inhalten arbeiten. Normale Strings bleiben unverändert.
        $twig->addFilter(new TwigFilter('localized', static function (mixed $value, string $locale, array $languages) use ($available): mixed {
            if (!is_array($value)) {
                return $value;
            }
            $keys = array_keys($value);
            if (array_diff($keys, $available) !== []) {
                return $value;
            }
            $resolved = $value[$locale] ?? '';
            if ($resolved === '' || $resolved === null) {
                $resolved = $value[$languages[0]] ?? reset($value);
            }
            return $resolved;
        }));
        $twig->addFunction(new TwigFunction('icon', [Icons::class, 'render'], ['is_safe' => ['html']]));
        // Liefert für ein Bild im uploads-Pool die passende Variante + srcset
        // (siehe Image::responsive()). $root steckt in der Closure, damit
        // Components den Helfer ohne weitere Argumente aufrufen können.
        $twig->addFunction(new TwigFunction('img_src', static fn (string $src, int $maxWidth = 1200): object => (object) Image::responsive($src, $maxWidth, $root)));

        if (isset($content['labels']) && is_array($content['labels'])) {
            $content['labels'] = self::substituteBusinessPlaceholders($content['labels'], $content['business'] ?? []);
        }

        $twig->addGlobal('config', $config);
        $twig->addGlobal('content', $content);
        $twig->addGlobal('csrf', $_SESSION['csrf'] ?? '');
        // Statisches Admin-CSS (dev/build-css.sh --admin); ?v= gegen Browser-Cache nach Core-Updates.
        $adminCss = $root . '/core/assets/admin.css';
        $twig->addGlobal('theme_preview', $themePreview);
        $twig->addGlobal('admin_css', is_file($adminCss) ? '/core/assets/admin.css?v=' . filemtime($adminCss) : '');
        $twig->addGlobal('icons', Icons::all());
        $twig->addGlobal('icon_categories', Icons::byCategory());
        $twig->addGlobal('languages', $languages);
        $twig->addGlobal('current_lang', $locale ?? $languages[0]);
        // Nur ein Server-seitiges Flag, NIE der Key selbst – so entscheidet das
        // Admin-UI, ob es KI-Buttons anzeigt (siehe Ai::configured()). Ohne
        // konfigurierten Key bleiben die Buttons schlicht weg.
        $twig->addGlobal('ai_available', Ai::configured($config));
        // Für interne Links, die absolut auf "/" + Anker verweisen (Header-Logo,
        // Nav/Footer/Sticky-Bar - müssen auch von /impressum, /datenschutz aus
        // funktionieren, ein bloßes "#anker" reicht dafür nicht): Sprachpräfix
        // davorsetzen, sonst landet man beim Klick immer in der Default-Sprache,
        // egal von welcher Sprachversion aus geklickt wurde.
        $twig->addGlobal('locale_prefix', ($locale !== null && $locale !== $languages[0]) ? '/' . $locale : '');

        return new self($root, $config, $content, $twig, $locale);
    }

    /**
     * Läuft bei jedem boot() (billig im eingeschwungenen Zustand - ein bis
     * zwei is_file()/count()-Prüfungen, kein Schreiben), holt aber einen
     * frischen Deploy ohne manuellen Setup-Schritt in einen lauffähigen
     * Zustand: fehlt data/config.json komplett, wird sie aus
     * config.example.json angelegt; fehlt darin (oder in einer bestehenden
     * config.json) noch jeder Admin-Benutzer, wird einer aus
     * ADMIN_USERNAME/ADMIN_PASSWORD in .env erzeugt (siehe Env, .env.example).
     * Ohne beide .env-Werte passiert nichts - der bisherige manuelle Weg
     * (docs/DEPLOY.md: config.example.json kopieren, dev/users.php add)
     * bleibt unverändert möglich.
     */
    private static function ensureSeeded(string $root): void
    {
        $dataDir = $root . '/data';
        // Beim allerersten Deploy per Git+FTP existiert data/ oft noch gar
        // nicht: config.json ist gitignored, content.json + .history/ sind
        // vom Deploy-Workflow bewusst ausgeschlossen (siehe
        // dev/templates/deploy-staging.yml - schützt spätere, live bearbeitete
        // Inhalte vor Überschreiben) - ohne diesen Ordner gäbe es unten
        // nirgends hin zu schreiben, writeJson() würde lautlos fehlschlagen.
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }

        $configPath = $dataDir . '/config.json';
        $config = is_file($configPath) ? self::readJson($configPath) : [];
        $changed = false;

        if ($config === []) {
            // config.json ist vom Deploy-Workflow absichtlich ausgeschlossen
            // (schützt spätere Live-Bearbeitungen an Theme/Layout vor dem
            // Überschreiben durch einen älteren Git-Stand) - beim allerersten
            // Deploy gibt es dadurch aber gar keine Projekt-Konfiguration.
            // config.seed.json (von exportProjectInstance() einmalig geschrieben,
            // bewusst NICHT ausgeschlossen) ist genau dafür da; config.example.json
            // als generischer Fallback, falls die Instanz nicht über den Export
            // entstanden ist.
            $seedPath = $dataDir . '/config.seed.json';
            $examplePath = $dataDir . '/config.example.json';
            $source = null;
            if (is_file($seedPath)) {
                $source = $seedPath;
            } elseif (is_file($examplePath)) {
                $source = $examplePath;
            }
            if ($source !== null) {
                $config = self::readJson($source);
                // Platzhalter-/Seed-Logins nie übernehmen - User kommen
                // ausschließlich aus ADMIN_USERS/ADMIN_USERNAME unten.
                $config['admin']['users'] = [];
                $changed = true;
            }
        }

        // Halb-Bootstrap nachtragen: Ein erster Boot auf einem frischen Host hat
        // config.json bereits mit den Admin-Usern aus .env angelegt (bootstrapUsers()
        // weiter unten), BEVOR die Seed-Dateien ins Repo kamen - config.json ist dann
        // zwar nicht mehr leer, aber ohne Theme/Layout nicht lauffähig und würde nie
        // mehr nachgeseedet. In dem Fall config.seed.json als Basis nachziehen;
        // bestehende Werte (v.a. die Admin-User) behalten Vorrang.
        if ($config !== [] && empty($config['theme'])) {
            $seedPath = $dataDir . '/config.seed.json';
            if (is_file($seedPath)) {
                $config = array_replace_recursive(self::readJson($seedPath), $config);
                $changed = true;
            }
        }

        $users = $config['admin']['users'] ?? [];
        if (!is_array($users) || $users === []) {
            $seeded = self::bootstrapUsers();
            if ($seeded !== []) {
                $config['admin']['users'] = $seeded;
                $changed = true;
            }
        }

        if ($changed) {
            self::writeJson($configPath, $config);
        }

        // content.json ist vom Deploy-Workflow absichtlich ausgeschlossen
        // (schützt spätere Live-Bearbeitungen vor dem Überschreiben durch
        // einen älteren Git-Stand) - beim allerersten Deploy gibt es dadurch
        // aber gar keins. content.seed.json (von exportProjectInstance() beim
        // Export einmalig geschrieben, NICHT ausgeschlossen) ist genau dafür
        // da; content.example.json als generischer Fallback, falls die
        // Instanz nicht über den Export entstanden ist.
        $contentPath = $dataDir . '/content.json';
        if (!is_file($contentPath)) {
            $seedPath = $dataDir . '/content.seed.json';
            $examplePath = $dataDir . '/content.example.json';
            $source = is_file($seedPath) ? $seedPath : (is_file($examplePath) ? $examplePath : null);
            if ($source !== null) {
                copy($source, $contentPath);
            }
        }

        // Gleicher Fall wie content.json: uploads/ ist vom Deploy-Workflow
        // ausgeschlossen (schützt später live hochgeladene Bilder), beim
        // allerersten Deploy kommen dadurch aber auch die ursprünglich beim
        // Export vorhandenen Projektbilder nie an. uploads.seed/ (von
        // exportProjectInstance() einmalig angelegt, bewusst NICHT
        // ausgeschlossen - anderer Ordnername als "uploads/**") liefert sie
        // nach, aber nur bei einem wirklich leeren uploads/ - keine
        // Datei-für-Datei-Merge-Logik, die später live Hochgeladenes anfassen
        // könnte.
        $uploadsDir = $root . '/uploads';
        $uploadsSeedDir = $root . '/uploads.seed';
        if (is_dir($uploadsSeedDir) && self::isEmptyDir($uploadsDir)) {
            self::copyDirRecursive($uploadsSeedDir, $uploadsDir);
            // Nach erfolgreichem Seed ist uploads.seed/ auf der Instanz
            // redundant: gelesen wird es ausschließlich hier, und nach dem
            // Kopieren nicht mehr. Es liegt bewusst NICHT unter uploads/**
            // (sonst würde der Deploy-Workflow es nie übertragen), ist damit
            // aber - anders als /data - vom .htaccess-/App-Schutz nicht
            // erfasst und öffentlich abrufbar, solange es liegen bleibt.
            // Deshalb nach dem Kopieren aufräumen. Verlust nur bei einem
            // leeren uploads/ ohne Full-Host-Wipe (dann greift cache/backups
            // oder ein Full-Redeploy); bei einem komplett leeren Host wandert
            // uploads.seed/ beim nächsten Deploy einfach wieder mit hoch und
            // seedet erneut (der CI-Hash ist mit dem Host ebenfalls weg).
            self::removeDirRecursive($uploadsSeedDir);
        }
    }

    /**
     * Erste Admin-Benutzer aus .env für den Bootstrap (siehe ensureSeeded()):
     * ADMIN_USERS als JSON-Liste ([{"username": ..., "password": ...,
     * "is_admin": true}, ...]) für mehrere Benutzer, sonst das bisherige
     * ADMIN_USERNAME/ADMIN_PASSWORD-Paar. Nur wirksam, solange config.json
     * noch KEINEN Admin-Benutzer hat - danach normal über den Admin-Bereich.
     *
     * @return list<array{username: string, password_hash: string, is_admin: bool}>
     */
    private static function bootstrapUsers(): array
    {
        $raw = trim(Env::get('ADMIN_USERS'));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $users = [];
                foreach ($decoded as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $username = trim((string) ($entry['username'] ?? ''));
                    $password = (string) ($entry['password'] ?? '');
                    if ($username === '' || $password === '') {
                        continue;
                    }
                    $users[] = [
                        'username' => $username,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'is_admin' => (bool) ($entry['is_admin'] ?? false),
                    ];
                }
                if ($users !== []) {
                    return $users;
                }
            }
        }

        $username = trim(Env::get('ADMIN_USERNAME'));
        $password = Env::get('ADMIN_PASSWORD');
        if ($username === '' || $password === '') {
            return [];
        }

        return [[
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin' => true,
        ]];
    }

    private static function isEmptyDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..' && $item !== '.gitkeep' && $item !== '.DS_Store') {
                return false;
            }
        }

        return true;
    }

    private static function copyDirRecursive(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..' || $item === '.DS_Store') {
                continue;
            }
            $from = "$src/$item";
            $to = "$dest/$item";
            if (is_dir($from)) {
                self::copyDirRecursive($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }

    private static function removeDirRecursive(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            if (is_dir($path)) {
                self::removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** @return list<string> Immer mindestens ["de"], erste Sprache ist die Default-/Fallback-Sprache. */
    public static function activeLanguages(array $config): array
    {
        $languages = $config['languages'] ?? ['de'];
        if (!is_array($languages) || $languages === []) {
            return ['de'];
        }

        return array_values(array_map('strval', $languages));
    }

    /**
     * Alle Sprachcodes, die im Content dieses Projekts als Sprach-Map-Schlüssel
     * erkannt werden: der Basis-Satz (AVAILABLE_LANGUAGES) plus optional per
     * config.languages_allowed ergänzte Codes – als Liste `["pl"]` oder als
     * Map `{"pl": "Polnisch"}`. Bewusst Merge statt Ersetzen: ein Projekt
     * erweitert die Erkennung, ohne bestehende Felder (z. B. ein inzwischen
     * inaktives "en") zu verlieren – und ohne dass deshalb Core aktualisiert
     * werden müsste. Siehe localizeContent()/languageLabels().
     *
     * @return list<string>
     */
    public static function availableLanguages(array $config): array
    {
        $codes = self::AVAILABLE_LANGUAGES;
        $extra = $config['languages_allowed'] ?? null;
        if (!is_array($extra)) {
            return $codes;
        }
        $extra = array_is_list($extra)
            ? array_map('strval', $extra)
            : array_map('strval', array_keys($extra));
        foreach ($extra as $code) {
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * {code => Anzeigename} aller im Admin auswählbaren Sprachen (Basis −
     * config.languages_disabled + config.languages_allowed). Map-Form des
     * Config-Werts darf Namen überschreiben/ergänzen; Liste bzw. unbekannte
     * Codes fallen auf den Code selbst bzw. den Basis-Namen zurück. "de" ist
     * nie ausblendbar und bleibt immer enthalten.
     *
     * @return array<string, string> code => Display-Name
     */
    public static function languageLabels(array $config): array
    {
        $labels = self::DEFAULT_LANGUAGE_LABELS;
        $extra = $config['languages_allowed'] ?? null;
        if (is_array($extra) && array_is_list($extra)) {
            foreach ($extra as $code) {
                $code = (string) $code;
                if ($code !== '') {
                    $labels[$code] ??= $code;
                }
            }
        } elseif (is_array($extra)) {
            foreach ($extra as $code => $label) {
                $code = (string) $code;
                if ($code === '') {
                    continue;
                }
                $labels[$code] = is_string($label) && $label !== '' ? $label : ($labels[$code] ?? $code);
            }
        }

        foreach (self::disabledCodes($config) as $code) {
            unset($labels[$code]);
        }

        return $labels;
    }

    /**
     * Per Projekt ausgeblendete Basis-Sprachen aus config.languages_disabled
     * (Liste der Codes). Sie verschwinden damit aus den Admin-Auswahlen
     * (Themes-Checkboxen, „Sprachen verwalten“) und der aktiven Liste, bleiben
     * aber Teil des Erkennungssatzes availableLanguages(): bereits gespeicherte
     * Sprach-Maps mit diesen Schlüsseln rendern weiterhin sauber (Fallback auf
     * die Default-Sprache), statt als rohe Arrays bei Twig zu landen – derselbe
     * „Merge statt Ersetzen“-Grundsatz wie bei languages_allowed. "de" ist nie
     * ausblendbar.
     *
     * @return list<string>
     */
    public static function disabledCodes(array $config): array
    {
        $disabled = $config['languages_disabled'] ?? null;
        if (!is_array($disabled)) {
            return [];
        }
        $out = [];
        foreach ($disabled as $code) {
            $code = (string) $code;
            if ($code === 'de' || $code === '' || !in_array($code, self::AVAILABLE_LANGUAGES, true)) {
                continue;
            }
            if (!in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }

    /**
     * Ermittelt aus dem Request-Pfad die Sprache (Präfix wie /fr/... – die
     * Default-Sprache hat keinen Präfix) und gibt den restlichen Pfad ohne
     * das Sprachkürzel zurück. Rein aus config.json gelesen, kein CMS-Objekt
     * nötig – wird in index.php vor CMS::boot() aufgerufen, um zu wissen,
     * mit welcher Sprache gebootet werden soll.
     *
     * @return array{locale: string, path: string}
     */
    public static function detectLocale(string $root, string $path): array
    {
        $config = self::readJson($root . '/data/config.json');
        $languages = self::activeLanguages($config);
        $default = $languages[0];

        $segments = $path === '' ? [] : explode('/', $path);
        $first = $segments[0] ?? '';
        if ($first !== '' && $first !== $default && in_array($first, $languages, true)) {
            array_shift($segments);

            return ['locale' => $first, 'path' => implode('/', $segments)];
        }

        return ['locale' => $default, 'path' => $path];
    }

    /**
     * Löst rekursiv jedes Feld, das als Sprach-Map gespeichert ist
     * ({"de": "...", "fr": "..."} – erkannt daran, dass es ein assoziatives
     * Array ist, dessen Schlüssel alle in $languages vorkommen), zu einem
     * einzelnen String für $locale auf. Alles andere (normale Strings,
     * Listen, verschachtelte Arrays ohne Sprach-Schlüssel) bleibt
     * unverändert – Components/Regions merken also nichts von Mehrsprachigkeit,
     * sie sehen immer nur den fertig aufgelösten Wert.
     *
     * @param list<string> $languages
     * @param list<string> $available Alle projektweit möglichen Sprachcodes (siehe availableLanguages())
     */
    private static function localizeContent(array $content, string $locale, array $languages, array $available): array
    {
        $result = [];
        foreach ($content as $key => $value) {
            if (!is_array($value)) {
                $result[$key] = $value;
                continue;
            }

            // Die Erkennung "ist das eine Sprach-Map?" prüft gegen ALLE
            // projektweit möglichen Sprachcodes (availableLanguages()), nicht
            // nur gegen die aktuell aktiven $languages: sonst würde ein bereits
            // gespeichertes Feld mit z. B. "en"/"fr"-Schlüsseln nach einem
            // Wechsel auf ein Theme mit weniger aktiven Sprachen plötzlich
            // nicht mehr erkannt und landete als rohes Array bei Twig.
            $keys = array_keys($value);
            $isLanguageMap = !array_is_list($value) && $keys !== [] && array_diff($keys, $available) === [];
            if ($isLanguageMap) {
                $resolved = $value[$locale] ?? '';
                if ($resolved === '' || $resolved === null) {
                    $resolved = $value[$languages[0]] ?? reset($value);
                }
                $result[$key] = $resolved;
                continue;
            }

            $result[$key] = self::localizeContent($value, $locale, $languages, $available);
        }

        return $result;
    }

    public function twig(): Environment
    {
        return $this->twig;
    }

    public function config(): array
    {
        return $this->config;
    }

    public function content(): array
    {
        return $this->content;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function render(): string
    {
        // sitemap.xml/robots.txt sind virtuell generierte Dateien und werden
        // bewusst HIER in core/ geroutet statt in web/index.php: index.php ist
        // nicht Teil des Core-Update-ZIPs (siehe docs/ARCHITECTURE.md), so
        // erreicht das Feature jede Instanz allein über ein Core-Update, ohne
        // dass die Instanz ihr web/ neu deployen muss.
        $requestPath = self::detectLocale($this->root, $this->requestPath())['path'];
        if ($requestPath === 'sitemap.xml') {
            header('Content-Type: application/xml; charset=utf-8');
            return $this->sitemapXml();
        }
        if ($requestPath === 'robots.txt') {
            header('Content-Type: text/plain; charset=utf-8');
            return $this->robotsTxt();
        }

        $cacheFile = $this->pageCacheFile('/');
        if ($cacheFile !== null && is_file($cacheFile)) {
            return (string) file_get_contents($cacheFile);
        }

        $sectionsHtml = '';
        $structuredDataJson = [];
        $needsSwiper = false;
        $openGroup = null;
        $blockIndex = 0;
        $zebraAccent = (bool) ($this->config['brand']['zebra_accent'] ?? false);
        foreach ($this->sortedSections() as $section) {
            $type = (string) ($section['type'] ?? '');
            if ($type === '') {
                continue;
            }

            $template = "components/{$type}/template.twig";
            if (!is_file($this->root . '/themes-and-plugins/' . $template)) {
                continue;
            }

            $componentSchema = $this->componentSchema($type);
            if ((bool) ($componentSchema['needs_swiper'] ?? false)) {
                $needsSwiper = true;
            }

            $group = trim((string) ($section['group'] ?? ''));
            if ($openGroup !== null && $openGroup !== $group) {
                $sectionsHtml .= '</div>';
                $openGroup = null;
            }
            if ($group !== '') {
                if ($openGroup === null) {
                    $sectionsHtml .= '<div id="group-' . self::slugify($group) . '"' . self::sectionBackgroundAttr($section, $blockIndex, $zebraAccent) . '>';
                    $openGroup = $group;
                    $blockIndex++;
                }
            } else {
                $sectionsHtml .= '<div' . self::sectionBackgroundAttr($section, $blockIndex, $zebraAccent) . '>';
                $blockIndex++;
            }

            $data = is_array($section['data'] ?? null) ? $section['data'] : [];
            $data = self::substituteBusinessPlaceholders($data, $this->content['business'] ?? []);
            $data['section'] = $section;
            $data['id'] = (string) ($section['id'] ?? $type);

            $dataHook = $this->root . "/themes-and-plugins/components/{$type}/data.php";
            if (is_file($dataHook)) {
                $provide = require $dataHook;
                if (is_callable($provide)) {
                    $data = $provide($data, $this->content, $this->config, $this->root);
                }
            }

            // Optionales Component-Mitbringsel für strukturierte Daten (JSON-LD):
            // Eine Component kann in ihrem data.php unter "structured_data" ein
            // Array (oder JSON-String) liefern, das der generische Sammler hier
            // einsammelt und später als eigenes <script type="application/ld+json">
            // in den <head> rendert. Der Key wird aus $data entfernt, damit die
            // Template-Variablen davon unberührt bleiben. "/"-Slashes bleiben
            // unescaped – wie in buildStructuredData(), um ein vorzeitiges
            // Schließen des Script-Tags durch "</script>"-artige Werte zu
            // verhindern.
            $structured = $data['structured_data'] ?? null;
            unset($data['structured_data']);
            if (is_string($structured) && trim($structured) !== '') {
                $structuredDataJson[] = $structured;
            } elseif (is_array($structured) && $structured !== []) {
                $json = json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                if ($json !== false && trim($json) !== '') {
                    $structuredDataJson[] = $json;
                }
            }

            $sectionsHtml .= $this->twig->render($template, $data);

            if ($group === '') {
                $sectionsHtml .= '</div>';
            }
        }
        if ($openGroup !== null) {
            $sectionsHtml .= '</div>';
        }

        $html = $this->renderShell($sectionsHtml, $needsSwiper, '/', $structuredDataJson);
        $this->writePageCache('/', $html);

        return $html;
    }

    /**
     * HTML-Attribut (class="..." oder style="...") für den Hintergrund eines
     * Section-Blocks (eine einzelne Section, oder eine ganze Anker-Gruppe).
     * "auto" (Default) alterniert nach Block-Position zwischen zwei Tönen –
     * normalerweise weiß/getönt, aber ein Theme kann brand.zebra_accent auf
     * true setzen, um stattdessen zwischen getönt/"accent" zu alternieren
     * (z. B. wenn das Theme nie ein reines Weiß im Seitenverlauf zeigt).
     * Für den Sonderfall (eine bestimmte Section soll in einer 3. Farbe oder
     * außerhalb der Reihe stehen) kann jede Section "background" explizit auf
     * "white"/"tint"/"accent" setzen, das überschreibt die Automatik nur für
     * diesen einen Block. "dark" rendert die Section durchgehend auf
     * Primärfarbe (dunkel) mit heller Schrift – passende Componenten blenden
     * ihre internen Farben über die Klasse "section-dark" um (siehe
     * components/content-block/template.twig).
     */
    private static function sectionBackgroundAttr(array $section, int $blockIndex, bool $zebraAccent = false): string
    {
        $bg = trim((string) ($section['background'] ?? 'auto'));
        if ($bg === 'white') {
            return ' class="bg-white"';
        }
        if ($bg === 'tint') {
            return ' class="bg-ash"';
        }
        if ($bg === 'accent') {
            return ' style="background-color: color-mix(in srgb, var(--brand-walnut) 8%, var(--brand-ash))"';
        }
        if ($bg === 'dark') {
            return ' class="bg-primary text-ash section-dark"';
        }
        if ($zebraAccent) {
            return $blockIndex % 2 === 1
                ? ' class="bg-ash"'
                : ' style="background-color: color-mix(in srgb, var(--brand-walnut) 8%, var(--brand-ash))"';
        }
        // body ist selbst bg-ash (siehe layout.twig) – "weiß" muss also explizit
        // gesetzt werden, "getönt" entspricht dem Seiten-Hintergrund.
        return $blockIndex % 2 === 1 ? ' class="bg-ash"' : ' class="bg-white"';
    }

    /**
     * Rendert eine Rechtstext-Seite (Impressum/Datenschutz) mit demselben
     * Header/Footer/Topbar wie die Startseite, aber ohne Sections.
     */
    public function renderLegal(string $key): ?string
    {
        $legal = $this->content['legal'][$key] ?? null;
        if (!is_array($legal)) {
            return null;
        }

        $cacheFile = $this->pageCacheFile('/' . $key);
        if ($cacheFile !== null && is_file($cacheFile)) {
            return (string) file_get_contents($cacheFile);
        }

        // Rückwärtskompatibel zum alten Einzelfeld "body" (vor der Umstellung
        // auf beliebig viele Textblöcke): existiert noch kein "blocks", aber
        // ein alter body-Wert, wird der als einzelner Block behandelt - kein
        // Migrationsskript nötig, heilt sich mit dem nächsten Speichern selbst.
        $blocks = $legal['blocks'] ?? [];
        if ($blocks === [] && trim((string) ($legal['body'] ?? '')) !== '') {
            $blocks = [['text' => $legal['body'], 'active' => true]];
        }

        $titles = ['impressum' => 'Impressum', 'datenschutz' => 'Datenschutzerklärung'];
        $body = $this->twig->render('legal.twig', [
            'title' => $titles[$key] ?? ucfirst($key),
            'blocks' => self::substituteBusinessPlaceholders($blocks, $this->content['business'] ?? []),
            'business' => $this->content['business'] ?? [],
            'show_business' => $key === 'impressum',
        ]);

        $html = $this->renderShell($body, false, '/' . $key);
        $this->writePageCache('/' . $key, $html);

        return $html;
    }

    /**
     * Generierte sitemap.xml: Startseite + Rechtstexte, je aktiver Sprache,
     * inklusive hreflang-Alternates. Absolute URLs brauchen zwingend die
     * Basis-URL aus {config.seo.domain} – ist sie nicht gesetzt, wird auf
     * den aktuellen Request-Host zurückgegriffen (beste Art, auch ohne
     * Konfiguration Standards-konform zu sein); ohne beides bleiben die
     * Einträge leer.
     */
    public function sitemapXml(): string
    {
        $base = rtrim((string) ($this->config['seo']['domain'] ?? ''), '/');
        if ($base === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if ($host !== '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $base = $scheme . '://' . $host;
            }
        }

        $contentFile = $this->root . '/data/content.json';
        $lastmod = is_file($contentFile) ? date('c', (int) filemtime($contentFile)) : '';

        $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            . 'xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach (self::sitemapEntries($this->config, $this->content) as $entry) {
            $langs = $entry['langs'];
            $primaryRel = trim((string) ($langs[$entry['default_lang']] ?? reset($langs) ?? ''));
            if ($primaryRel === '' || $base === '') {
                continue;
            }
            $primary = $base . $primaryRel;
            $out .= "  <url>\n";
            $out .= '    <loc>' . self::xmlEscape($primary) . "</loc>\n";
            if ($lastmod !== '') {
                $out .= '    <lastmod>' . $lastmod . "</lastmod>\n";
            }
            foreach ($langs as $lang => $rel) {
                if ($rel === '') {
                    continue;
                }
                $out .= '    <xhtml:link rel="alternate" hreflang="' . htmlspecialchars($lang, ENT_XML1, 'UTF-8')
                    . '" href="' . self::xmlEscape($base . $rel) . '" />' . "\n";
            }
            $out .= "  </url>\n";
        }

        return $out . "</urlset>\n";
    }

    /**
     * Generierte robots.txt: folgt der noindex-Einstellung (Inhalt → SEO →
     * "Von Suchmaschinen ausschließen") und verweist auf die Sitemap.
     */
    public function robotsTxt(): string
    {
        $noindex = (bool) ($this->content['seo']['robots_noindex'] ?? false);
        $base = rtrim((string) ($this->config['seo']['domain'] ?? ''), '/');

        $lines = ['User-agent: *'];
        $lines[] = $noindex ? 'Disallow: /' : 'Allow: /';
        if (!$noindex && $base !== '') {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Basis der Sitemap-Einträge: Seiten (Startseite + vorhandene Rechtstexte)
     * für alle aktiven Sprachen, jeweils mit relativer URL je Sprache. Statisch,
     * damit sie ohne CMS-Instanz testbar ist (siehe tests/run.php). Absolute
     * URLs macht erst sitemapXml() daraus (Basis-URL aus config.seo.domain bzw.
     * Fallback: Request-Host).
     *
     * @return list<array{path: string, langs: array<string, string>, default_lang: string}>
     */
    public static function sitemapEntries(array $config, array $content): array
    {
        $languages = self::activeLanguages($config);
        $default = $languages[0];

        $pages = ['/'];
        foreach (['impressum', 'datenschutz'] as $key) {
            if (is_array($content['legal'][$key] ?? null)) {
                $pages[] = '/' . $key;
            }
        }

        $entries = [];
        foreach ($pages as $path) {
            $langs = [];
            foreach ($languages as $lang) {
                $langs[$lang] = $lang !== $default ? '/' . $lang . $path : $path;
            }
            $entries[] = ['path' => $path, 'langs' => $langs, 'default_lang' => $default];
        }

        return $entries;
    }

    /** Reiner Request-Pfad aus $_SERVER (ohne Locale-Präfix-Auflösung – die macht detectLocale()). */
    private function requestPath(): string
    {
        return trim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
    }

    private static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Rendert eine Layout-Region (Header/Footer/Topbar/Sticky-Bar/Cookie-Banner).
     * Der gemeinsame Pool (themes-and-plugins/<region>s/<variant>/) ist die
     * alleinige Quelle: Solange die konfigurierte Variante dort liegt, rendert
     * sie – auch wenn das aktive Theme zusätzlich eine eigene Kopie mitbringt
     * (themes-and-plugins/themes/<theme>/<region>/, siehe Admin::buildTheme()).
     * So erreicht ein Fix im Pool jede Instanz, ohne dass eine eingefrorene
     * Theme-Kopie ihn überschattet. Die Theme-Kopie bleibt ausschließlich
     * Fallback für Varianten ohne Pool-Ordner (schlanke Exports, siehe
     * Admin::exportProjectInstance()), damit ein Theme-Ordner auch allein
     * portierbar bleibt. Leerer $variant ("— Keine —" im Theme-Builder) →
     * erst die Theme-Kopie als Fallback, sonst rendert die Region nichts;
     * einen nicht existierenden Pool-Pfad lädt diese Funktion nie.
     *
     * @param array<string, mixed> $vars
     */
    private function renderRegion(string $region, string $poolFolder, string $variant, array $vars): string
    {
        if ($variant !== '' && is_file($this->root . "/themes-and-plugins/{$poolFolder}/{$variant}/template.twig")) {
            return $this->twig->render("{$poolFolder}/{$variant}/template.twig", $vars);
        }

        $theme = (string) ($this->config['theme'] ?? '');
        if ($theme !== '' && is_file($this->root . "/themes-and-plugins/themes/{$theme}/{$region}/template.twig")) {
            return $this->twig->render("themes/{$theme}/{$region}/template.twig", $vars);
        }

        return '';
    }

    /**
     * Baut Header/Topbar/Footer um das übergebene Body-HTML herum (Startseite wie Rechtsseiten).
     *
     * @param list<string> $sectionStructuredData Vom generischen Sammler in
     *     render() gesammelte JSON-LD-Strings der Sections (siehe dort).
     */
    private function renderShell(string $bodyHtml, bool $needsSwiper, string $currentPath, array $sectionStructuredData = []): string
    {
        $site = $this->content['site'] ?? [];
        $nav = $this->content['nav'] ?? [];
        $layout = $this->config['layout'] ?? [];
        $headerKey = (string) ($layout['header'] ?? 'standard-nav');
        $footerKey = (string) ($layout['footer'] ?? 'simple-footer');

        $header = $this->renderRegion('header', 'headers', $headerKey, [
            'site' => $site,
            'nav' => $nav,
        ]);

        $contactId = null;
        $faqId = null;
        foreach ($this->sortedSections() as $section) {
            $type = (string) ($section['type'] ?? '');
            if ($contactId === null && $type === 'contact-form') {
                $contactId = (string) ($section['id'] ?? '');
            }
            if ($faqId === null && $type === 'faq') {
                $faqId = (string) ($section['id'] ?? '');
            }
        }

        $topbar = '';
        if ((bool) ($layout['topbar']['enabled'] ?? false)) {
            $topbarKey = (string) ($layout['topbar']['theme'] ?? 'standard');
            $topbar = $this->renderRegion('topbar', 'topbars', $topbarKey, [
                'phone' => $this->content['business']['phone'] ?? '',
                'text' => self::substituteBusinessPlaceholders((string) ($this->content['topbar']['text'] ?? ''), $this->content['business'] ?? []),
                'cta_label' => $this->content['topbar']['cta_label'] ?? '',
                'cta_href' => $contactId !== null ? '/#' . $contactId : '',
            ]);
        }

        $footer = $this->renderRegion('footer', 'footers', $footerKey, [
            'site' => $site,
            'nav' => $nav,
            'year' => (int) date('Y'),
        ]);

        $stickyBarKey = (string) ($layout['stickybar'] ?? 'icon-rail');
        $stickyBar = $this->renderRegion('stickybar', 'stickybars', $stickyBarKey, [
            'business' => $this->content['business'] ?? [],
            'contact_id' => $contactId,
            'faq_id' => $faqId,
        ]);

        $cookieBannerKey = (string) ($layout['cookiebanner'] ?? 'corner');
        $cookieBanner = $this->renderRegion('cookiebanner', 'cookiebanners', $cookieBannerKey, []);

        $theme = (string) ($this->config['theme'] ?? '');
        $themeCssUrl = null;
        if ($theme !== '' && is_file($this->root . "/themes-and-plugins/themes/{$theme}/theme.css")) {
            $themeCssUrl = "/themes-and-plugins/themes/{$theme}/theme.css";
        }

        // Vorproduziertes Tailwind-CSS (dev/build-css.sh, theme-agnostisch über
        // die CSS-Variablen in :root) – existiert es, rendert layout.twig ohne
        // Play-CDN. Fehlt es, bleibt das bisherige Verhalten (CDN) unverändert.
        $staticCssUrl = null;
        if ($theme !== '' && is_file($this->root . "/themes-and-plugins/themes/{$theme}/tailwind.css")) {
            $staticCssUrl = "/themes-and-plugins/themes/{$theme}/tailwind.css";
        }

        $brand = $this->config['brand'] ?? [];
        // RGB-Tripel (z. B. "53 94 75") zu den Hex-Farbwerten: nötig für das
        // vorproduzierte Tailwind-CSS, das mit <alpha-value> (text-walnut/70)
        // arbeitet, ohne zur Laufzeit zu kompilieren. Die CDN-Branch ignorieren
        // sie einfach.
        $hexToRgb = static function (string $hex): string {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
                return '';
            }

            return implode(' ', array_map('hexdec', str_split($hex, 2)));
        };
        $brandRgb = [
            'primary' => $hexToRgb((string) ($brand['primary'] ?? '#355E4B')),
            'secondary' => $hexToRgb((string) ($brand['secondary'] ?? '#B87333')),
            'walnut' => $hexToRgb((string) ($brand['walnut'] ?? '#241E18')),
            'ash' => $hexToRgb((string) ($brand['ash'] ?? '#E7E4DC')),
        ];

        // Strukturierte Daten als Liste von JSON-LD-Strings: das LocalBusiness-
        // Grundschema aus buildStructuredData() plus die von den Components
        // beigesteuerten Schemas (FAQPage, Reviews, …) – layout.twig rendert
        // je Eintrag ein eigenes <script type="application/ld+json">.
        $structuredDataList = [];
        $baseStructuredData = $this->buildStructuredData();
        if ($baseStructuredData !== null) {
            $structuredDataList[] = $baseStructuredData;
        }
        foreach ($sectionStructuredData as $json) {
            if (is_string($json) && trim($json) !== '') {
                $structuredDataList[] = $json;
            }
        }

        $business = $this->content['business'] ?? [];

        // E-Mail/Telefon vor Adress-Sammlern schützen (siehe Obfuscate) – einmal
        // über das fertige HTML, vor dem Seiten-Cache.
        return Obfuscate::html($this->twig->render('layout.twig', [
            'site' => $site,
            'seo' => $this->content['seo'] ?? [],
            'og_image_size' => $this->ogImageSize((string) ($this->content['seo']['og_image'] ?? '')),
            'current_path' => $currentPath,
            'structured_data' => $structuredDataList,
            'topbar' => $topbar,
            'header' => $header,
            'sections' => $bodyHtml,
            'footer' => $footer,
            'sticky_bar' => $stickyBar,
            'cookie_banner' => $cookieBanner,
            'needs_swiper' => $needsSwiper,
            'theme_css_url' => $themeCssUrl,
            'static_css_url' => $staticCssUrl,
            'brand' => $brand,
            'brand_rgb' => $brandRgb,
            'business' => $this->content['business'] ?? [],
            'contact_id' => $contactId,
        ]), [(string) ($business['phone'] ?? ''), (string) ($business['mobile'] ?? '')]);
    }

    /**
     * Generiert ein einfaches LocalBusiness-JSON-LD aus den bereits vorhandenen
     * Betriebsdaten (kein zusätzliches Admin-Feld nötig) – hilft Suchmaschinen,
     * Name/Adresse/Kontakt/Öffnungszeiten als Rich-Snippet zu erkennen.
     */
    private function buildStructuredData(): ?string
    {
        $business = $this->content['business'] ?? [];
        $name = trim((string) ($business['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $seo = $this->content['seo'] ?? [];
        $base = rtrim((string) ($this->config['seo']['domain'] ?? ''), '/');

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $name,
        ];

        if ($base !== '') {
            $data['url'] = $base . '/';
        }
        if (($business['phone'] ?? '') !== '') {
            $data['telephone'] = $business['phone'];
        }
        // Bewusst KEINE E-Mail: JSON-LD lässt sich nicht verschleiern (siehe
        // Obfuscate), Google braucht sie nicht – die Telefonnummer bleibt (lokale Suche).
        if (($business['owner'] ?? '') !== '') {
            $data['founder'] = ['@type' => 'Person', 'name' => $business['owner']];
        }
        if (($business['street'] ?? '') !== '' || ($business['city'] ?? '') !== '') {
            $data['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => (string) ($business['street'] ?? ''),
                'postalCode' => (string) ($business['zip'] ?? ''),
                'addressLocality' => (string) ($business['city'] ?? ''),
                'addressCountry' => 'DE',
            ];
        }
        if (($business['hours'] ?? '') !== '') {
            $data['openingHours'] = $business['hours'];
        }

        // hasMap: bevorzugt die Google-Places-ID (schon für Google-Reviews erfasst,
        // config.json → apis.google.place_id) für einen exakten Maps-Treffer, sonst
        // dieselbe Adress-Suche wie die Karte im Kontaktformular (business.maps_query).
        $placeId = trim((string) ($this->config['apis']['google']['place_id'] ?? ''));
        if ($placeId !== '') {
            $data['hasMap'] = 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode($placeId);
        } else {
            $mapsQuery = trim((string) ($business['maps_query'] ?? ''));
            if ($mapsQuery === '') {
                $mapsQuery = trim($name . ' ' . ($business['street'] ?? '') . ' ' . ($business['zip'] ?? '') . ' ' . ($business['city'] ?? ''));
            }
            if ($mapsQuery !== '') {
                $data['hasMap'] = 'https://www.google.com/maps?q=' . rawurlencode($mapsQuery);
            }
        }

        $ogImage = (string) ($seo['og_image'] ?? '');
        if ($ogImage !== '') {
            $data['image'] = $base !== '' ? $base . '/' . ltrim($ogImage, '/') : '/' . ltrim($ogImage, '/');
        }

        // Slashes bewusst NICHT unescaped lassen: verhindert, dass ein "</script>"-artiger
        // Wert (z. B. im Firmennamen) das <script type="application/ld+json">-Tag vorzeitig schließt.
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $json !== false ? $json : null;
    }

    /** @return array{width: int, height: int}|null */
    private function ogImageSize(string $ogImage): ?array
    {
        if ($ogImage === '' || str_starts_with($ogImage, 'http')) {
            return null;
        }

        $path = $this->root() . '/' . ltrim($ogImage, '/');
        if (!is_file($path)) {
            return null;
        }

        $size = @getimagesize($path);

        return $size !== false ? ['width' => $size[0], 'height' => $size[1]] : null;
    }

    /** @return array<string, mixed> */
    private function componentSchema(string $type): array
    {
        $path = $this->root . "/themes-and-plugins/components/{$type}/schema.json";
        return is_file($path) ? self::readJson($path) : [];
    }

    /**
     * Ersetzt `{business.<feld>}`-Platzhalter (per Dropdown im Admin in Text-/Textarea-
     * Felder einfügbar) durch den aktuellen Wert aus content.json → business. Läuft
     * rekursiv über beliebig verschachtelte Section-Daten (Repeater etc.). Statisch,
     * damit `boot()` sie auch auf `content.labels` anwenden kann, bevor die Instanz
     * existiert (Twig-Global wird direkt in boot() registriert).
     */
    private static function substituteBusinessPlaceholders(mixed $value, array $business): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::substituteBusinessPlaceholders($v, $business);
            }
            return $value;
        }

        if (!is_string($value) || !str_contains($value, '{business.')) {
            return $value;
        }

        return preg_replace_callback('/\{business\.([a-z_]+)\}/', static function (array $m) use ($business): string {
            $val = $business[$m[1]] ?? '';
            if (is_array($val)) {
                $val = $val['de'] ?? (reset($val) ?: '');
            }
            return (string) $val;
        }, $value) ?? $value;
    }

    /** @return list<array<string, mixed>> */
    public function sortedSections(): array
    {
        $sections = $this->content['sections'] ?? [];
        if (!is_array($sections)) {
            return [];
        }

        usort($sections, static function ($a, $b): int {
            return ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
        });

        return array_values($sections);
    }

    public static function slugify(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }

        $translit = strtr($s, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
        ]);
        $lower = mb_strtolower($translit, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';

        return trim($slug, '-');
    }

    /**
     * Dateipfad der gecachten Public-Seite, oder null wenn nicht gecacht
     * werden soll. Kein Cache:
     *  - Nicht-GET-Requests (die Public-Seite rendert nur bei GET, aber
     *    doppelt hält besser),
     *  - Generator-Instanzen (dev/-Ordner neben web/): dort wird ständig an
     *    Templates gearbeitet, ein Cache würde beim Entwickeln nur verwirren.
     *    Deployte Instanzen haben kein dev/ und nutzen den Cache.
     */
    private function pageCacheFile(string $path): ?string
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return null;
        }
        if (is_dir(dirname($this->root) . '/dev')) {
            return null;
        }

        return $this->root . self::PAGE_CACHE_DIR . '/' . self::pageCachePrefix($path) . '-' . $this->pageCacheKey($path) . '.html';
    }

    /** Menschlich lesbarer Dateiname-Teil je Seite ("/" -> home, "/impressum" -> impressum). */
    private static function pageCachePrefix(string $path): string
    {
        $slug = trim(strtr($path, '/', '-'), '-');

        return $slug === '' ? 'home' : $slug;
    }

    /**
     * Signatur der Public-Seite: ändert sich eine der Abhängigkeiten (Inhalte,
     * Config, genutzte Component/Region, Theme-/Core-/Components-Stand,
     * Sprache, Jahreszahl im Footer), ist der Hash ein anderer → der alte
     * Cache-Treffer wird nicht mehr gefunden und beim nächsten Schreiben
     * aufgeräumt. content.json/config.json gehen als Prüfsumme ein, damit
     * auch zwei Speicherungen innerhalb derselben Sekunde sicher
     * invalidieren (filemtime allein hätte nur Sekundengranularität).
     */
    private function pageCacheKey(string $path): string
    {
        $theme = (string) ($this->config['theme'] ?? '');
        $signature = [];
        foreach (['/data/config.json', '/data/content.json'] as $rel) {
            $file = $this->root . $rel;
            $signature[] = $rel . ':' . (is_file($file) ? (string) md5_file($file) : '-');
        }

        $deps = [
            '/core/version.json',
            '/themes-and-plugins/components/version.json',
            '/core/templates/layout.twig',
            '/core/templates/legal.twig',
            '/core/assets/style.css',
        ];
        if ($theme !== '') {
            $deps[] = "/themes-and-plugins/themes/{$theme}/theme.json";
            $deps[] = "/themes-and-plugins/themes/{$theme}/theme.css";
            $deps[] = "/themes-and-plugins/themes/{$theme}/tailwind.css";
        }
        foreach ($this->sortedSections() as $section) {
            $type = (string) ($section['type'] ?? '');
            if ($type === '') {
                continue;
            }
            $deps[] = "/themes-and-plugins/components/{$type}/template.twig";
            $deps[] = "/themes-and-plugins/components/{$type}/data.php";
        }
        $layout = $this->config['layout'] ?? [];
        foreach (['header', 'footer', 'stickybar', 'cookiebanner'] as $region) {
            $deps[] = $this->regionTemplate($theme, $region, (string) ($layout[$region] ?? ''));
        }
        if ((bool) ($layout['topbar']['enabled'] ?? false)) {
            $deps[] = $this->regionTemplate($theme, 'topbar', (string) ($layout['topbar']['theme'] ?? 'standard'));
        }

        foreach ($deps as $rel) {
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            $file = $this->root . $rel;
            $signature[] = $rel . ':' . (is_file($file) ? filemtime($file) . '-' . filesize($file) : '-');
        }

        array_unshift($signature, $path . '|' . ($this->locale ?? '') . '|' . date('Y'));

        return hash('sha1', implode('|', $signature));
    }

    /** Root-relativer Pfad des Region-Templates – Pool zuerst, Theme-Kopie nur Fallback (wie renderRegion()). */
    private function regionTemplate(string $theme, string $region, string $variant): ?string
    {
        if ($variant !== '' && is_file($this->root . "/themes-and-plugins/{$region}s/{$variant}/template.twig")) {
            return "/themes-and-plugins/{$region}s/{$variant}/template.twig";
        }
        if ($theme !== '') {
            $themed = "/themes-and-plugins/themes/{$theme}/{$region}/template.twig";
            if (is_file($this->root . $themed)) {
                return $themed;
            }
        }

        return null;
    }

    private function writePageCache(string $path, string $html): void
    {
        $file = $this->pageCacheFile($path);
        if ($file === null) {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $html, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return;
        }
        // Ältere Stände desselben Pfads (frühere Content-/Theme-Signaturen)
        // aufräumen, damit cache/pages/ nicht unbegrenzt wächst.
        $prefix = self::pageCachePrefix($path) . '-';
        foreach (glob($dir . '/' . $prefix . '*.html') ?: [] as $old) {
            if ($old !== $file) {
                @unlink($old);
            }
        }
    }

    /**
     * Leert den Public-Seiten-Cache. Nötig für Aktionen, die gerenderte Seiten
     * verändern, ohne content.json/config.json anzufassen (z. B. "Thumbnails
     * nachziehen" – die Bild-URLs in srcset/width/stammen aus den Varianten).
     */
    public static function clearPageCache(string $root): void
    {
        foreach (glob($root . self::PAGE_CACHE_DIR . '/*.html') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Das aktive Theme ist die Quelle für das Layout: Farben/Schriften (brand) und
     * die Region-Varianten kommen zur Laufzeit aus themes/<theme>/theme.json, nicht
     * aus der beim Anwenden kopierten Fassung in data/config.json. Grund: config.json
     * gehört dem Server und wird nie deployt – eine Theme-Änderung im Generator käme
     * sonst nie live an. theme.json geht mit jedem Deploy mit.
     *
     * In config.json bleiben: welches Theme aktiv ist, Inhaltsschalter wie
     * topbar.enabled und brand-Schlüssel, die das Theme nicht kennt. Ohne theme.json
     * (oder ohne config.theme) gilt die Config unverändert. Nur im Speicher – die
     * Datei wird nicht umgeschrieben.
     */
    /** Vom Sync in jede Instanz geschriebenes, mit deploytes aktives Theme. */
    public const ACTIVE_THEME_FILE = '/themes-and-plugins/active-theme.json';

    public static function deployedTheme(string $root): string
    {
        $theme = (string) (self::readJson($root . self::ACTIVE_THEME_FILE)['theme'] ?? '');

        return preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $theme) === 1 && is_file($root . '/themes-and-plugins/themes/' . $theme . '/theme.json') ? $theme : '';
    }

    /** Farben, die ein Farbset (theme.json → palettes) überschreibt. */
    public const PALETTE_COLORS = ['primary', 'secondary', 'ash', 'walnut'];

    /** Zentrale Farbset-Bibliothek (eine JSON-Datei je Set, nur im Generator gepflegt). */
    public const PALETTE_DIR = '/themes-and-plugins/palettes';

    /**
     * Alle Sets der Bibliothek: slug => {label, primary, secondary, ash, walnut}.
     * In einer Instanz liegen hier nur die Sets, die ihr Theme anbietet (Sync).
     *
     * @return array<string, array<string, string>>
     */
    public static function poolPalettes(string $root): array
    {
        $out = [];
        foreach (glob($root . self::PALETTE_DIR . '/*.json') ?: [] as $file) {
            $slug = basename($file, '.json');
            $set = self::readJson($file);
            if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) === 1 && $set !== []) {
                $out[$slug] = $set;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Farbsets, die ein Theme anbietet: eigene (theme.json → palettes) plus die aus
     * der Bibliothek angebotenen (theme.json → palette_refs). Bei gleichem Namen
     * gewinnt das eigene Set; fehlende Bibliotheks-Sets werden übersprungen.
     *
     * @return array<string, array<string, string>>
     */
    public static function themePalettes(string $root, array $theme): array
    {
        $own = is_array($theme['palettes'] ?? null) ? $theme['palettes'] : [];
        $refs = is_array($theme['palette_refs'] ?? null) ? $theme['palette_refs'] : [];
        $out = [];
        foreach ($refs as $slug) {
            $slug = (string) $slug;
            if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
                continue;
            }
            $set = self::readJson($root . self::PALETTE_DIR . '/' . $slug . '.json');
            if ($set !== []) {
                $out[$slug] = $set + ['shared' => true];
            }
        }
        foreach ($own as $slug => $set) {
            if (is_array($set)) {
                $out[(string) $slug] = $set;
            }
        }

        return $out;
    }

    public static function applyThemeToConfig(string $root, array $config): array
    {
        $name = (string) ($config['theme'] ?? '');
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $name)) {
            return $config;
        }
        $theme = self::readJson($root . '/themes-and-plugins/themes/' . $name . '/theme.json');
        if ($theme === []) {
            return $config;
        }
        if (is_array($theme['brand'] ?? null)) {
            $config['brand'] = array_replace(is_array($config['brand'] ?? null) ? $config['brand'] : [], $theme['brand']);
        }
        // Gewähltes Farbset (Inhaltsschalter config.palette, im Admin der Website
        // wählbar): nur die vier Farben über brand legen. Unbekanntes Set – etwa nach
        // einem Theme-Wechsel – oder ungültige Werte: es bleibt beim Standard.
        $palette = self::themePalettes($root, $theme)[(string) ($config['palette'] ?? '')] ?? null;
        if (is_array($palette)) {
            foreach (self::PALETTE_COLORS as $key) {
                if (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($palette[$key] ?? '')) === 1) {
                    $config['brand'][$key] = (string) $palette[$key];
                }
            }
        }
        if (is_array($theme['layout'] ?? null)) {
            foreach (['header', 'footer', 'stickybar', 'cookiebanner'] as $region) {
                if (array_key_exists($region, $theme['layout'])) {
                    $config['layout'][$region] = (string) $theme['layout'][$region];
                }
            }
            if (array_key_exists('topbar', $theme['layout'])) {
                $topbar = $config['layout']['topbar'] ?? [];
                $config['layout']['topbar'] = [
                    'enabled' => (bool) (is_array($topbar) ? ($topbar['enabled'] ?? false) : false),
                    'theme' => (string) $theme['layout']['topbar'],
                ];
            }
        }

        return $config;
    }

    /**
     * Session-Cookie nur per HTTP (kein JS-Zugriff), SameSite=Lax und auf HTTPS
     * zusätzlich Secure. Lokal (php -S, http://) bleibt Secure aus, sonst
     * schickt der Browser den Cookie nie zurück und kein Login klappt.
     *
     * @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string}
     */
    public const SESSION_LIFETIME = 28800;

    public static function sessionCookieOptions(): array
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        return ['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
    }

    /** Für index.php: Session mit den Cookie-Optionen oben starten. */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params(self::sessionCookieOptions());
        // 8 h statt PHP-Standard 24 min: wer den Admin offen lässt und später
        // etwas absendet (z. B. „Core aktualisieren“), war sonst schon abgemeldet.
        session_start(['use_strict_mode' => 1, 'gc_maxlifetime' => self::SESSION_LIFETIME]);
    }

    /**
     * Für Instanzen, deren index.php (liegt außerhalb von core/, kommt also mit
     * keinem Core-Update mit) noch ein nacktes session_start() macht: den
     * Session-Cookie mit denselben Flags erneut setzen, der Browser ersetzt den
     * alten damit.
     */
    public static function hardenSessionCookie(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }
        $opts = self::sessionCookieOptions();
        setcookie(session_name(), (string) session_id(), [
            'expires' => 0,
            'path' => $opts['path'],
            'secure' => $opts['secure'],
            'httponly' => true,
            'samesite' => $opts['samesite'],
        ]);
    }

    public static function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        $data = json_decode($raw !== false ? $raw : '{}', true);

        return is_array($data) ? $data : [];
    }

    public static function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('JSON konnte nicht geschrieben werden.');
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Datei konnte nicht gespeichert werden.');
        }
        rename($tmp, $path);
    }
}
