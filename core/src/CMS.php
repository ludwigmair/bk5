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
     * Alle Sprachcodes, die im Content jemals als Übersetzungs-Map-Schlüssel
     * vorkommen können (unabhängig davon, welche Sprachen im aktiven Theme
     * gerade ausgewählt sind) – siehe localizeContent().
     */
    public const AVAILABLE_LANGUAGES = ['de', 'en', 'fr', 'it', 'es', 'nl'];

    public function __construct(
        private readonly string $root,
        private readonly array $config,
        private readonly array $content,
        private readonly Environment $twig,
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
        $content = self::readJson($root . '/data/content.json');
        $languages = self::activeLanguages($config);

        if ($locale !== null) {
            $content = self::localizeContent($content, $locale, $languages);
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
        $twig->addFunction(new TwigFunction('icon', [Icons::class, 'render'], ['is_safe' => ['html']]));

        if (isset($content['labels']) && is_array($content['labels'])) {
            $content['labels'] = self::substituteBusinessPlaceholders($content['labels'], $content['business'] ?? []);
        }

        $twig->addGlobal('config', $config);
        $twig->addGlobal('content', $content);
        $twig->addGlobal('csrf', $_SESSION['csrf'] ?? '');
        $twig->addGlobal('icons', Icons::all());
        $twig->addGlobal('icon_categories', Icons::byCategory());
        $twig->addGlobal('languages', $languages);
        $twig->addGlobal('current_lang', $locale ?? $languages[0]);
        // Für interne Links, die absolut auf "/" + Anker verweisen (Header-Logo,
        // Nav/Footer/Sticky-Bar - müssen auch von /impressum, /datenschutz aus
        // funktionieren, ein bloßes "#anker" reicht dafür nicht): Sprachpräfix
        // davorsetzen, sonst landet man beim Klick immer in der Default-Sprache,
        // egal von welcher Sprachversion aus geklickt wurde.
        $twig->addGlobal('locale_prefix', ($locale !== null && $locale !== $languages[0]) ? '/' . $locale : '');

        return new self($root, $config, $content, $twig);
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
        $configPath = $root . '/data/config.json';
        $config = is_file($configPath) ? self::readJson($configPath) : [];
        $changed = false;

        if ($config === []) {
            $examplePath = $root . '/data/config.example.json';
            if (is_file($examplePath)) {
                $config = self::readJson($examplePath);
                // Platzhalter-Hash ("GENERATE_MIT: ...") nie übernehmen - sonst
                // hielte ihn die Prüfung unten fälschlich für einen echten
                // Benutzer und würde nie aus ADMIN_USERNAME/ADMIN_PASSWORD seeden.
                $config['admin']['users'] = [];
                $changed = true;
            }
        }

        $users = $config['admin']['users'] ?? [];
        if (!is_array($users) || $users === []) {
            $username = trim(Env::get('ADMIN_USERNAME'));
            $password = Env::get('ADMIN_PASSWORD');
            if ($username !== '' && $password !== '') {
                $config['admin']['users'] = [[
                    'username' => $username,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'is_admin' => true,
                ]];
                $changed = true;
            }
        }

        if ($changed) {
            self::writeJson($configPath, $config);
        }
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
     */
    private static function localizeContent(array $content, string $locale, array $languages): array
    {
        $result = [];
        foreach ($content as $key => $value) {
            if (!is_array($value)) {
                $result[$key] = $value;
                continue;
            }

            // Die Erkennung "ist das eine Sprach-Map?" prüft gegen ALLE
            // jemals möglichen Sprachcodes (AVAILABLE_LANGUAGES), nicht nur
            // gegen die aktuell aktiven $languages: sonst würde ein bereits
            // gespeichertes Feld mit z. B. "en"/"fr"-Schlüsseln nach einem
            // Wechsel auf ein Theme mit weniger aktiven Sprachen plötzlich
            // nicht mehr erkannt und landete als rohes Array bei Twig.
            $keys = array_keys($value);
            $isLanguageMap = !array_is_list($value) && $keys !== [] && array_diff($keys, self::AVAILABLE_LANGUAGES) === [];
            if ($isLanguageMap) {
                $resolved = $value[$locale] ?? '';
                if ($resolved === '' || $resolved === null) {
                    $resolved = $value[$languages[0]] ?? reset($value);
                }
                $result[$key] = $resolved;
                continue;
            }

            $result[$key] = self::localizeContent($value, $locale, $languages);
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
        $sectionsHtml = '';
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

            $sectionsHtml .= $this->twig->render($template, $data);

            if ($group === '') {
                $sectionsHtml .= '</div>';
            }
        }
        if ($openGroup !== null) {
            $sectionsHtml .= '</div>';
        }

        return $this->renderShell($sectionsHtml, $needsSwiper, '/');
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
     * diesen einen Block.
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

        return $this->renderShell($body, false, '/' . $key);
    }

    /**
     * Rendert eine Layout-Region (Header/Footer/Topbar/Sticky-Bar/Cookie-Banner).
     * Ein aktives Theme kann eine eigene, portable Kopie einer Region mitbringen
     * (themes-and-plugins/themes/<theme>/<region>/template.twig, siehe
     * Admin::buildTheme()) – die hat Vorrang vor dem gemeinsamen Pool
     * (themes-and-plugins/<region>s/<variant>/), damit ein Theme-Ordner
     * eigenständig portierbar bleibt, ohne dass CMS::boot() dafür etwas
     * Besonderes wissen muss. Kein Theme oder keine eigene Kopie → ganz normal
     * aus dem Pool, wie vorher. Leerer $variant ("— Keine —" im Theme-Builder)
     * → Region rendert nichts, statt einen nicht existierenden Pool-Pfad zu laden.
     *
     * @param array<string, mixed> $vars
     */
    private function renderRegion(string $region, string $poolFolder, string $variant, array $vars): string
    {
        $theme = (string) ($this->config['theme'] ?? '');
        if ($theme !== '' && is_file($this->root . "/themes-and-plugins/themes/{$theme}/{$region}/template.twig")) {
            return $this->twig->render("themes/{$theme}/{$region}/template.twig", $vars);
        }

        if ($variant === '') {
            return '';
        }

        return $this->twig->render("{$poolFolder}/{$variant}/template.twig", $vars);
    }

    /** Baut Header/Topbar/Footer um das übergebene Body-HTML herum (Startseite wie Rechtsseiten). */
    private function renderShell(string $bodyHtml, bool $needsSwiper, string $currentPath): string
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

        return $this->twig->render('layout.twig', [
            'site' => $site,
            'seo' => $this->content['seo'] ?? [],
            'og_image_size' => $this->ogImageSize((string) ($this->content['seo']['og_image'] ?? '')),
            'current_path' => $currentPath,
            'structured_data' => $this->buildStructuredData(),
            'topbar' => $topbar,
            'header' => $header,
            'sections' => $bodyHtml,
            'footer' => $footer,
            'sticky_bar' => $stickyBar,
            'cookie_banner' => $cookieBanner,
            'needs_swiper' => $needsSwiper,
            'theme_css_url' => $themeCssUrl,
            'brand' => $this->config['brand'] ?? [],
            'business' => $this->content['business'] ?? [],
            'contact_id' => $contactId,
        ]);
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
        if (($business['email'] ?? '') !== '') {
            $data['email'] = $business['email'];
        }
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
