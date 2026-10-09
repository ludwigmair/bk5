<?php

declare(strict_types=1);

namespace Core;

final class Admin
{
    use AdminParts\UpdateActions;
    use AdminParts\ProjectInstanceActions;
    use AdminParts\LanguageActions;
    use AdminParts\UserActions;
    use AdminParts\ImageActions;
    use AdminParts\DevImportActions;

    public function __construct(private readonly CMS $cms)
    {
    }

    public function handle(): void
    {
        $action = (string) ($_GET['do'] ?? $_POST['do'] ?? '');
        CMS::hardenSessionCookie();

        // Läuft VOR jeder Session-/Login-Prüfung: ein CI-Job (siehe
        // dev/templates/deploy-staging.yml) hat keine Session, authentifiziert
        // sich stattdessen per Bearer-Token gegen BACKUP_TOKEN aus .env.
        if ($action === 'backup') {
            $this->runBackup();
            return;
        }

        // Für den Admin-Dialog „Sitzung abgelaufen“: Status + aktuelles CSRF-Token,
        // ohne Login-Pflicht (sonst gäbe es für eine abgelaufene Sitzung keine
        // Antwort). Hält die Sitzung nebenbei wach. Nur same-origin lesbar.
        if ($action === 'session-status') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['logged_in' => $this->isLoggedIn(), 'csrf' => $_SESSION['csrf'] ?? '']);
            return;
        }

        if ($action === 'logout') {
            unset($_SESSION['admin']);
            header('Location: ?admin=1');
            exit;
        }

        if (!$this->isLoggedIn()) {
            $this->handleLogin();
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->assertCsrf();
            if (in_array($action, self::ADMIN_ONLY_ACTIONS, true) && !$this->currentIsAdmin()) {
                $this->redirect('Keine Berechtigung.');
            }
            // Layout-Sperre: Themes (und damit Header/Footer/…-Varianten) werden nur im
            // Generator entwickelt und per Sync/Deploy übernommen – in einer Instanz
            // würde eine Änderung beim nächsten Sync überschrieben (bzw. ihn blockieren).
            if (in_array($action, ['apply-theme', 'build-theme', 'delete-theme', 'theme-preview', 'theme-preview-end', 'save-palette-pool'], true) && !$this->hasDevTools()) {
                $this->redirectToPanel('Themes werden nur im Generator bearbeitet – dort ändern und per Sync/Deploy übernehmen.', 'panel-theme');
            }
            if ($action === 'check-update') {
                $this->runCheckUpdate();
                return;
            }
            if ($action === 'check-components-update') {
                $this->runCheckComponentsUpdate();
                return;
            }
            match ($action) {
                'save' => $this->saveContent(),
                'add' => $this->addSection(),
                'delete' => $this->deleteSection(),
                'update' => $this->runUpdate(),
                'components-update' => $this->runComponentsUpdate(),
                'user-add' => $this->addUser(),
                'user-remove' => $this->removeUser(),
                'user-setpw' => $this->setUserPassword(),
                'apply-theme' => $this->applyTheme(),
                'build-theme' => $this->buildTheme(),
                'delete-theme' => $this->deleteTheme(),
                'toggle-topbar' => $this->toggleTopbar(),
                'set-palette' => $this->setPalette(),
                'save-palette-pool' => $this->savePalettePool(),
                'mail-test' => $this->sendTestMail(),
                'theme-preview' => $this->startThemePreview(),
                'theme-preview-end' => $this->endThemePreview(),
                'image-upload' => $this->uploadImage(),
                'image-delete' => $this->deleteImage(),
                'image-delete-many' => $this->deleteImagesMany(),
                'inquiry-delete' => $this->deleteInquiry(),
                'inquiries-delete-all' => $this->deleteAllInquiries(),
                'image-import' => $this->importImages(),
                'regen-thumbs' => $this->regenerateThumbnails(),
                'reorder-sections' => $this->reorderSections(),
                'switch-project' => $this->switchProject(),
                'export-import' => $this->exportDevImport(),
                'restore-import' => $this->restoreImportBaseline(),
                'restore-history' => $this->restoreHistory(),
                'build-update-package' => $this->buildUpdatePackage(),
                'delete-update-package' => $this->deleteUpdatePackage(),
                'build-components-update-package' => $this->buildComponentsUpdatePackage(),
                'delete-components-update-package' => $this->deleteComponentsUpdatePackage(),
                'export-instance' => $this->exportProjectInstance(),
                'instance-sync' => $this->syncProjectInstance(),
                'build-project-package' => $this->buildProjectPackage(),
                'download-project-package' => $this->downloadProjectPackage(),
                'delete-project-package' => $this->deleteProjectPackage(),
                'import-project-package' => $this->importProjectPackage(),
                'import-translations' => $this->importTranslations(),
                'language-add' => $this->languageAdd(),
                'language-remove' => $this->languageRemove(),
                'language-enable' => $this->languageEnable(),
                'ai-rewrite' => $this->aiRewrite(),
                'ai-seo' => $this->aiSeo(),
                default => $this->redirect('Unbekannte Aktion.'),
            };
            return;
        }

        if ($action === 'check-update') {
            if (!$this->currentIsAdmin()) {
                $this->redirect('Keine Berechtigung.');
            }
            $this->runCheckUpdate();
            return;
        }

        if ($action === 'check-components-update') {
            if (!$this->currentIsAdmin()) {
                $this->redirect('Keine Berechtigung.');
            }
            $this->runCheckComponentsUpdate();
            return;
        }

        if ($action === 'manual') {
            $this->renderManual();
            return;
        }

        if ($action === 'doc') {
            $this->renderDevDoc((string) ($_GET['file'] ?? ''));
            return;
        }

        if ($action === 'download-update-package') {
            if (!$this->currentIsAdmin()) {
                $this->redirect('Keine Berechtigung.');
            }
            $this->downloadUpdatePackage();
            return;
        }

        if ($action === 'download-components-update-package') {
            if (!$this->currentIsAdmin()) {
                $this->redirect('Keine Berechtigung.');
            }
            $this->downloadComponentsUpdatePackage();
            return;
        }

        if ($action === 'export-translations') {
            if (!$this->currentIsAdmin()) {
                $this->redirect('Keine Berechtigung.');
            }
            $this->exportTranslations();
            return;
        }

        $this->renderDashboard();
    }

    /**
     * Zeigt das Benutzerhandbuch (core/MANUAL.md) als gerenderte Seite im Admin –
     * für alle eingeloggten Accounts, keine Admin-Rolle nötig, da es sich an
     * Redakteure richtet, nicht nur an Admins. Liegt bewusst in core/, nicht in
     * docs/ (wie dev/ nie deployt) - core/ geht mit jedem Deploy, jedem
     * Core-Update und jedem "Projekt-Instanz erstellen"-Export automatisch mit,
     * das Handbuch also immer.
     */
    private function renderManual(): void
    {
        $path = $this->cms->root() . '/core/MANUAL.md';
        $markdown = is_file($path) ? (string) file_get_contents($path) : '';
        $content = CMS::readJson($this->cms->root() . '/data/content.json');

        echo $this->cms->twig()->render('admin-manual.twig', [
            'site_name' => $this->displayString($content['site']['title'] ?? '', 'CMS'),
            'html' => Markdown::render($markdown),
            'toc' => Markdown::headings($markdown),
            'current_user' => $this->currentUsername(),
        ]);
    }

    /**
     * Entwickler-Dokus (docs/*.md neben web/) – nur im Generator: docs/ liegt
     * außerhalb des Docroots und geht nie mit einem Deploy/Export mit, eine
     * Instanz hat also schlicht keine. Datei + Titel (erste #-Überschrift) für
     * das Menü „Dokus“.
     *
     * @return list<array{file: string, title: string}>
     */
    private function devDocs(): array
    {
        if (!$this->hasDevTools()) {
            return [];
        }
        $out = [];
        foreach (glob(dirname($this->cms->root()) . '/docs/*.md') ?: [] as $path) {
            $title = basename($path, '.md');
            if (preg_match('/^#\s+(.+)$/m', (string) file_get_contents($path), $m) === 1) {
                $title = trim($m[1]);
            }
            $out[] = ['file' => basename($path), 'title' => $title];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $out;
    }

    /**
     * Eine Doku aus docs/ lesbar gerendert (gleiche Seite wie das Handbuch,
     * öffnet im Admin in einem neuen Fenster). Nur Dateien aus devDocs() –
     * kein freier Pfad. Der generierte TOC-Block (dev/build-toc.php, GitHub-
     * Anker) wird weggelassen, die Sprungnavigation baut die Seite selbst;
     * Links auf andere Dokus zeigen wieder auf diese Ansicht.
     */
    private function renderDevDoc(string $file): void
    {
        $docs = $this->devDocs();
        $files = array_column($docs, 'file');
        if (!in_array($file, $files, true)) {
            http_response_code(404);
            echo 'Doku nicht gefunden.';
            return;
        }
        $markdown = (string) file_get_contents(dirname($this->cms->root()) . '/docs/' . $file);
        $markdown = preg_replace('/<!-- TOC-START.*?TOC-END -->\n?/s', '', $markdown) ?? $markdown;
        $html = preg_replace_callback('/href="(?:\.\.\/|docs\/)*([A-Za-z0-9_-]+\.md)(#[^"]*)?"/', static function (array $m) use ($files): string {
            return in_array($m[1], $files, true)
                ? 'href="?admin=1&amp;do=doc&amp;file=' . rawurlencode($m[1]) . '"'
                : $m[0];
        }, Markdown::render($markdown)) ?? '';
        $title = $docs[array_search($file, $files, true)]['title'];

        echo $this->cms->twig()->render('admin-manual.twig', [
            'site_name' => $title,
            'kicker' => 'Doku · docs/' . $file,
            'html' => $html,
            'toc' => Markdown::headings($markdown),
            'docs' => $docs,
            'current_file' => $file,
            'current_user' => $this->currentUsername(),
        ]);
    }

    /**
     * Aktionen, die einen Account mit Admin-Rolle voraussetzen (Benutzer verwalten, Core-Update).
     * Bildverwaltung (image-upload/-delete/-import) gehört zu Stammdaten und bleibt für alle
     * eingeloggten Accounts nutzbar – nur Beschriftungen/Benutzer/Update sind Admin-only.
     */
    private const ADMIN_ONLY_ACTIONS = [
        'check-update', 'update', 'check-components-update', 'components-update',
        'user-add', 'user-remove', 'user-setpw',
        'apply-theme', 'build-theme', 'delete-theme', 'toggle-topbar', 'set-palette', 'save-palette-pool', 'mail-test', 'theme-preview', 'theme-preview-end',
        'add', 'delete', 'reorder-sections',
        'switch-project', 'export-import', 'restore-import', 'restore-history',
        'build-update-package', 'download-update-package', 'delete-update-package',
        'build-components-update-package', 'download-components-update-package', 'delete-components-update-package',
        'export-instance', 'instance-sync',
        'build-project-package', 'download-project-package', 'delete-project-package', 'import-project-package',
        'export-translations', 'import-translations',
        'language-add', 'language-remove', 'language-enable',
    ];

    /**
     * Impressum/Datenschutz: beliebig viele Rich-Text-Blöcke statt eines
     * einzelnen Textfelds, weil gerade diese zwei Seiten oft viele Absätze/
     * Abschnitte brauchen (siehe saveContent(), renderDashboard()).
     */
    private const LEGAL_FIELDS = [
        ['name' => 'blocks', 'type' => 'repeater', 'label' => 'Textblöcke', 'fields' => [
            ['name' => 'text', 'type' => 'textarea', 'label' => 'Text'],
        ]],
    ];

    /**
     * Liest die CHANGELOG.md des Update-Pakets (installierte Version) und
     * rendert sie für das „Änderungen“-Feld im Admin. version = neueste
     * Version im Changelog (erste ##-Überschrift).
     *
     * @return array{version: string, html: string}
     */
    private function changelogInfo(string $path): array
    {
        $markdown = is_file($path) ? (string) file_get_contents($path) : '';
        if (trim($markdown) === '') {
            return ['version' => '', 'html' => ''];
        }
        $version = '';
        if (preg_match('/^##\s+([0-9]+\.[0-9]+\.[0-9]+)/m', $markdown, $m) === 1) {
            $version = $m[1];
        }
        return ['version' => $version, 'html' => Markdown::render($markdown)];
    }

    private const LOGIN_MAX_FAILS = 5;
    private const LOGIN_LOCK_SECONDS = 600;

    private function renderDashboard(array $extra = []): void
    {
        $content = CMS::readJson($this->cms->root() . '/data/content.json');
        $schemas = $this->scanSchemas();
        $flash = $_SESSION['flash'] ?? '';
        unset($_SESSION['flash']);
        $flashCommand = $_SESSION['flash_command'] ?? '';
        unset($_SESSION['flash_command']);
        $flashAfter = $_SESSION['flash_after'] ?? '';
        unset($_SESSION['flash_after']);
        $updateInfo = $_SESSION['update_info'] ?? null;
        unset($_SESSION['update_info']);
        $componentsUpdateInfo = $_SESSION['components_update_info'] ?? null;
        unset($_SESSION['components_update_info']);
        $hasDevTools = $this->hasDevTools();

        $activeThemeName = $this->cms->config()['theme'] ?? null;
        $activeThemeData = null;
        foreach ($this->scanThemes() as $candidate) {
            if (($candidate['name'] ?? '') === $activeThemeName) {
                $activeThemeData = $candidate;
                break;
            }
        }

        echo $this->cms->twig()->render('admin.twig', array_merge([
            'site_name' => $this->displayString($content['site']['title'] ?? '', 'CMS'),
            'version' => CMS::readJson($this->cms->root() . '/core/version.json')['version'] ?? '1.0.0',
            'core_update_pending' => $this->coreModifiedSincePackage()
                || trim((string) (CMS::readJson($this->cms->root() . '/core/version.json')['checksum'] ?? '')) === '',
            'content' => $content,
            'schemas' => $schemas,
            'layoutSchemas' => [
                'header' => $this->layoutSchemaFor('header'),
                'footer' => $this->layoutSchemaFor('footer'),
                'topbar' => $this->layoutSchemaFor('topbar'),
                'stickybar' => $this->layoutSchemaFor('stickybar'),
                'cookiebanner' => $this->layoutSchemaFor('cookiebanner'),
            ],
            'themes' => $this->scanThemes(),
            'active_theme' => $activeThemeName,
            'active_theme_data' => $activeThemeData,
            'active_theme_palettes' => CMS::themePalettes($this->cms->root(), is_array($activeThemeData) ? $activeThemeData : []),
            'palette_pool' => CMS::poolPalettes($this->cms->root()),
            'regionVariants' => [
                'header' => $this->scanRegionVariants('header'),
                'footer' => $this->scanRegionVariants('footer'),
                'topbar' => $this->scanRegionVariants('topbar'),
                'stickybar' => $this->scanRegionVariants('stickybar'),
                'cookiebanner' => $this->scanRegionVariants('cookiebanner'),
            ],
            'flash' => $flash,
            'flash_command' => $flashCommand,
            'flash_after' => $flashAfter,
            // > 0 nur nach dem Content-Speichern (siehe redirectToEdited()):
            // das Flash-Notification schwebt dann über der Save-Leiste, statt
            // oben unterm Scroll zu verschwinden.
            'restore_scroll' => max(0, min(200000, (int) ($_GET['focus_scroll'] ?? 0))),
            'csrf' => $_SESSION['csrf'] ?? '',
            'users' => $this->usersList(),
            'current_user' => $this->currentUsername(),
            'current_is_admin' => $this->currentIsAdmin(),
            'content_rev' => $this->contentRevision(),
            'mail_from' => Mail::fromAddress($this->cms->config(), $this->cms->content()),
            'uploads' => $this->listUploads(),
            'inquiries' => Inquiries::all($this->cms->root(), Inquiries::retentionDays($this->cms->config())),
            'inquiries_retention_days' => Inquiries::retentionDays($this->cms->config()),
            'active_languages' => CMS::activeLanguages($this->cms->config()),
            'available_languages' => $this->availableLanguages(),
            'dev_imports' => $hasDevTools ? $this->scanDevImports() : [],
            'theme_preview' => $hasDevTools ? (string) ($_SESSION['theme_preview'] ?? '') : '',
            'pending_project_switch' => (string) ($_SESSION['pending_project_switch'] ?? ''),
            'dev_import_source' => (string) ($this->cms->config()['dev_import_source'] ?? ''),
            'active_dev_import' => $this->cms->config()['dev_import'] ?? null,
            'content_history' => $this->listHistory(),
            'update_packages' => $this->listUpdatePackages(),
            'core_modified' => $this->coreModifiedSincePackage(),
            'update_info' => $updateInfo,
            'core_changelog' => $this->changelogInfo($this->cms->root() . '/core/CHANGELOG.md'),
            'components_changelog' => $this->changelogInfo($this->componentsDir() . '/CHANGELOG.md'),
            'extra_languages' => $this->extraLanguages(),
            'disabled_languages' => $this->disabledLanguages(),
            'components_version' => CMS::readJson($this->componentsDir() . '/version.json')['version'] ?? '1.0.0',
            'components_update_pending' => $this->componentsModifiedSincePackage()
                || trim((string) (CMS::readJson($this->componentsDir() . '/version.json')['checksum'] ?? '')) === '',
            'components_update_packages' => $this->listComponentsUpdatePackages(),
            'components_modified' => $this->componentsModifiedSincePackage(),
            'components_update_info' => $componentsUpdateInfo,
            'has_dev_tools' => $hasDevTools,
            'dev_docs' => $this->devDocs(),
            'dev_instances' => $hasDevTools ? $this->devInstances() : [],
            'project_packages' => $this->projectPackages(),
            'seo_schemas' => $this->detectSeoSchemas(),
        ], $extra));
    }

    /**
     * KI-Button in den Bearbeitungsfeldern (admin-field.twig → data-ai-rewrite):
     * schlägt bis zu drei Alternativformulierungen für den aktuellen Feldwert vor.
     * Antworte als JSON; die Buttons erscheinen nur, wenn ein API-Key
     * konfiguriert ist (ai_available in CMS::boot), hier wird zusätzlich geprüft.
     */
    private function aiRewrite(): void
    {
        $config = $this->cms->config();
        if (!Ai::configured($config)) {
            $this->respondJson(['ok' => false, 'message' => 'Kein API-Key konfiguriert (config.json → apis.openai.api_key).', 'alternatives' => []]);
            return;
        }

        $label = trim((string) ($_POST['label'] ?? '')) ?: 'Text';
        $value = (string) ($_POST['value'] ?? '');
        $lang = $this->pickAdminLang((string) ($_POST['lang'] ?? ''));
        $section = trim((string) ($_POST['section'] ?? ''));

        $business = $this->cms->content()['business'] ?? [];
        $system = 'Du bist ein erfahrener deutschsprachiger Texter für Unternehmenswebsites. '
            . 'Du überarbeitest einzelne Texte so, dass sie präzise, konkret und vertrauensvoll klingen – '
            . 'ohne Marketing-Floskeln, ohne Übertreibungen. '
            . 'Behalte Fakten und Fachbegriffe unverändert bei und erfinde nichts. '
            . 'Die Fassungen sollen jeweils anders beginnen und anders formuliert sein, aber denselben Inhalt transportieren.';

        $user = 'Website/Einrichtung: ' . $this->displayString($business['name'] ?? '', '')
            . "\nFeld: " . $label
            . ($section !== '' ? "\nZugehörige Sektion: " . $section : '')
            . "\nSprache: " . $lang
            . "\n\nAktueller Text:\n" . ($value !== '' ? $value : '(leer – bitte einen Entwurf liefern)');

        $this->respondJson(Ai::alternatives($config, $system, $user));
    }

    /**
     * KI-Button im SEO-Panel (admin.twig → data-ai-seo): generiert aus Firmenname,
     * Seitentitel und den Sektions-Überschriften passende SEO-Titel/-Description-Paare.
     */
    private function aiSeo(): void
    {
        $config = $this->cms->config();
        if (!Ai::configured($config)) {
            $this->respondJson(['ok' => false, 'message' => 'Kein API-Key konfiguriert (config.json → apis.openai.api_key).', 'suggestions' => []]);
            return;
        }

        $lang = $this->pickAdminLang((string) ($_POST['lang'] ?? ''));
        $content = $this->cms->content();
        $business = $content['business'] ?? [];
        $site = $content['site'] ?? [];

        $headings = $this->sectionHeadings();
        $context = 'Website-Titel: ' . $this->displayString($site['title'] ?? '', '')
            . "\nFirmenname: " . $this->displayString($business['name'] ?? '', '')
            . ($this->displayString($site['tagline'] ?? '', '') !== '' ? "\nUnterzeile: " . $this->displayString($site['tagline']) : '')
            . ($headings !== [] ? "\nSeitenabschnitte: " . implode(' · ', $headings) : '')
            . "\nSprache: " . $lang;

        $system = 'Du bist SEO- und UX-Texter für eine kleine Unternehmenswebsite. '
            . 'Erstelle zum Titel und den Sektionen eine passende Title-Meta (maximal 60 Zeichen) '
            . 'und Description-Meta (maximal 155 Zeichen). '
            . 'Klingt natürlich, kein Keyword-Stuffing, keine Werbeversprechen. '
            . 'Die Vorschläge sollen unterschiedliche Blickwinkel bieten.';

        $result = Ai::seoSuggestions($config, $system, $context);
        $this->respondJson(['ok' => $result['ok'], 'message' => $result['message'], 'suggestions' => $result['suggestions']]);
    }

    /** Dezimale Sparschätzung der aktiven Schemas für das SEO-Panel (statische Ist-Liste). */
    private function detectSeoSchemas(): array
    {
        $content = $this->cms->content();
        $business = $content['business'] ?? [];

        $hasFaq = false;
        $hasReviews = false;
        foreach (($content['sections'] ?? []) as $section) {
            $type = (string) ($section['type'] ?? '');
            $data = is_array($section['data'] ?? null) ? $section['data'] : [];
            if ($type === 'faq') {
                foreach (($data['items'] ?? []) as $item) {
                    if (($item['active'] ?? true) !== false
                        && ($this->displayString($item['question'] ?? '', '') !== '' || $this->displayString($item['answer'] ?? '', '') !== '')) {
                        $hasFaq = true;
                    }
                }
            }
            if ($type === 'google-reviews') {
                foreach (($data['fallback_reviews'] ?? []) as $review) {
                    if (($review['active'] ?? true) !== false && $this->displayString($review['text'] ?? '', '') !== '') {
                        $hasReviews = true;
                    }
                }
            }
        }

        // Achtung: die Komponenten-Zusatzschemas erscheinen nur, wenn die jeweilige
        // Sektion inhaltlich gefüllt ist – leere Sektionen steuern kein Schema bei.
        return [
            ['type' => 'local', 'label' => 'LocalBusiness (Firmendaten)', 'active' => $this->displayString($business['name'] ?? '', '') !== ''],
            ['type' => 'faq', 'label' => 'FAQPage (FAQ-Sektion)', 'active' => $hasFaq],
            ['type' => 'reviews', 'label' => 'AggregateRating + Review (Bewertungs-Sektion)', 'active' => $hasReviews],
        ];
    }

    /** Kopfzeilen der Sektionen (jeweils erstes plausibles Textelement) für den SEO-KI-Kontext. */
    private function sectionHeadings(): array
    {
        $headings = [];
        $wanted = ['heading', 'claim', 'title', 'kicker', 'subtitle', 'name'];

        foreach (($this->cms->content()['sections'] ?? []) as $section) {
            $data = is_array($section['data'] ?? null) ? $section['data'] : [];

            $candidates = [];
            $langCodes = CMS::availableLanguages($this->cms->config());
            $collect = static function (array $arr) use (&$collect, &$candidates, $langCodes): void {
                foreach ($arr as $key => $value) {
                    $key = (string) $key;
                    if (in_array($key, $langCodes, true)) {
                        continue; // Sprach-Map: nur von außen als Feld, nicht die Rohwerte
                    }
                    if (is_string($value) && trim($value) !== '') {
                        $candidates[] = ['k' => strtolower($key), 'v' => trim($value)];
                    } elseif (is_array($value) && !array_is_list($value)) {
                        $collect($value); // benannte Objekte/Sections rekursiv abklappern
                    }
                }
            };
            $collect($data);

            $chosen = '';
            foreach ($wanted as $w) {
                foreach ($candidates as $c) {
                    if ($c['k'] === $w) {
                        $chosen = $c['v'];
                        break 2;
                    }
                }
            }
            if ($chosen === '') {
                foreach ($candidates as $c) {
                    if (!in_array($c['k'], ['lead', 'intro', 'description', 'text', 'answer'], true) && mb_strlen($c['v']) <= 90) {
                        $chosen = $c['v'];
                        break;
                    }
                }
            }
            if ($chosen !== '') {
                $headings[] = $chosen;
            }
        }

        return array_slice($headings, 0, 8);
    }

    /** JSON an den Browser (KI-Endpoints), REST-mäßig ohne Redirect wie die übrigen Formular-Aktionen. */
    private function respondJson(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function saveContent(): void
    {
        $path = $this->cms->root() . '/data/content.json';
        // Hat seit dem Laden des Formulars jemand anderes gespeichert? Dann nicht
        // blind überschreiben, sondern nachfragen (siehe renderSaveConflict()).
        // Ein leeres content_rev (Formular aus einem älteren Core) prüft nicht.
        $rev = (string) ($_POST['content_rev'] ?? '');
        if ($rev !== '' && !hash_equals($this->contentRevision(), $rev)) {
            $this->renderSaveConflict();
            return;
        }
        $content = CMS::readJson($path);
        $this->pushHistory($content);
        $incoming = $_POST['sections'] ?? [];
        if (!is_array($incoming)) {
            $this->redirect('Ungültige Formulardaten.');
        }

        // Anker-Umbenennungen vorab sammeln+validieren, bevor die IDs feststehen -
        // ungültige/doppelte Wünsche fallen einzeln auf die bisherige ID zurück,
        // statt die ganze Speicherung abzubrechen oder Daten zu verlieren.
        $idMap = [];
        $usedIds = array_map(static fn ($id) => (string) $id, array_keys($incoming));
        $renameErrors = [];
        foreach ($incoming as $oldId => $row) {
            $wanted = trim((string) ($row['anchor_id'] ?? ''));
            $oldId = (string) $oldId;
            if ($wanted === '' || $wanted === $oldId) {
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9-]{1,59}$/', $wanted)
                || str_starts_with($wanted, 'panel-')
                || str_starts_with($wanted, 'group-')
                || in_array($wanted, ['top', 'site-sticky-head'], true)
            ) {
                $renameErrors[] = $wanted . ' (ungültig)';
                continue;
            }
            if (in_array($wanted, $usedIds, true)) {
                $renameErrors[] = $wanted . ' (bereits vergeben)';
                continue;
            }
            $idMap[$oldId] = $wanted;
            $usedIds[array_search($oldId, $usedIds, true)] = $wanted;
        }

        $sections = [];
        foreach ($incoming as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) $id;
            $type = (string) ($row['type'] ?? '');
            $schema = $this->schemaFor($type);
            $data = $this->normalizeData($schema['fields'] ?? [], $row['data'] ?? [], 'sections[' . $id . '][data]');
            $background = (string) ($row['background'] ?? 'auto');
            if (!in_array($background, ['auto', 'white', 'tint', 'accent', 'dark'], true)) {
                $background = 'auto';
            }
            $sections[] = [
                'id' => $idMap[$id] ?? $id,
                'type' => $type,
                'sort' => (int) ($row['sort'] ?? 0),
                'label' => $this->translatableValue($row['label'] ?? null, ''),
                'group' => trim((string) ($row['group'] ?? '')),
                'background' => $background,
                'data' => $data,
            ];
        }

        usort($sections, static fn ($a, $b) => $a['sort'] <=> $b['sort']);
        $content['sections'] = array_values($sections);

        // Nav-Links, die auf einen umbenannten Anker zeigten, automatisch mitziehen -
        // sonst wäre der eigentliche Zweck des Umbenennens (lesbare Anker) sofort
        // wieder ein kaputter Nav-Link.
        if ($idMap !== [] && isset($content['nav']) && is_array($content['nav'])) {
            foreach ($content['nav'] as &$navItem) {
                $href = (string) ($navItem['href'] ?? '');
                foreach ($idMap as $oldId => $newId) {
                    if ($href === '#' . $oldId || $href === '/#' . $oldId) {
                        $navItem['href'] = str_replace('#' . $oldId, '#' . $newId, $href);
                    }
                }
            }
            unset($navItem);
        }
        if (isset($_POST['site']) && is_array($_POST['site'])) {
            foreach (['title', 'tagline', 'logo_text'] as $field) {
                $content['site'][$field] = $this->translatableValue($_POST['site'][$field] ?? null, $content['site'][$field] ?? '');
            }
            $content['site']['logo_image'] = trim((string) ($_POST['site']['logo_image'] ?? $content['site']['logo_image'] ?? ''));
        }

        if (isset($_POST['business']) && is_array($_POST['business'])) {
            foreach (['name', 'owner', 'street', 'zip', 'city', 'phone', 'email', 'vat_id', 'register', 'hours', 'maps_query'] as $field) {
                $content['business'][$field] = $this->translatableValue($_POST['business'][$field] ?? null, $content['business'][$field] ?? '');
            }
        }

        if (isset($_POST['seo']) && is_array($_POST['seo'])) {
            foreach (['title', 'description', 'og_title', 'og_description'] as $field) {
                $content['seo'][$field] = $this->translatableValue($_POST['seo'][$field] ?? null, $content['seo'][$field] ?? '');
            }
            foreach (['og_image', 'favicon'] as $field) {
                $content['seo'][$field] = trim((string) ($_POST['seo'][$field] ?? $content['seo'][$field] ?? ''));
            }
            $content['seo']['robots_noindex'] = ($_POST['seo']['robots_noindex'] ?? '0') === '1';
        }

        if ($this->currentIsAdmin() && isset($_POST['labels']) && is_array($_POST['labels'])) {
            foreach ([
                'nav_menu', 'read_more', 'sticky_call', 'sticky_mail', 'sticky_inquiry', 'sticky_top',
                'cookie_text', 'cookie_accept', 'cookie_necessary', 'cookie_privacy_link',
                'back_to_home', 'footer_impressum', 'footer_datenschutz',
                'mail_subject', 'mail_body',
            ] as $field) {
                $content['labels'][$field] = $this->translatableValue($_POST['labels'][$field] ?? null, $content['labels'][$field] ?? '');
            }
        }

        foreach (['header', 'footer', 'topbar', 'stickybar', 'cookiebanner'] as $region) {
            if (!isset($_POST[$region]) || !is_array($_POST[$region])) {
                continue;
            }
            $schema = $this->layoutSchemaFor($region);
            $content[$region] = $this->normalizeData($schema['fields'] ?? [], $_POST[$region], $region);
        }

        if ($this->currentIsAdmin() && isset($_POST['nav']) && is_array($_POST['nav'])) {
            $nav = [];
            foreach ($_POST['nav'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = $this->translatableValue($item['label'] ?? null, '');
                $href = trim((string) ($item['href'] ?? ''));
                $labelPrimary = is_array($label) ? trim((string) ($label['de'] ?? reset($label) ?: '')) : $label;
                if ($labelPrimary === '' || $href === '') {
                    continue;
                }
                $nav[] = ['label' => $label, 'href' => $href, 'active' => isset($item['active'])];
            }
            $content['nav'] = $nav;
        }

        if (isset($_POST['legal']) && is_array($_POST['legal'])) {
            // Impressum/Datenschutz bestehen seit der Umstellung auf beliebig
            // viele Textblöcke aus einem repeater "blocks" statt einem
            // einzelnen "body" - normalizeData() liefert dafür dieselbe
            // Add/Entfernen/Sortieren-Mechanik wie bei jedem anderen
            // Repeater-Feld (z. B. FAQ, Galerie), ohne eigene Logik dafür.
            foreach (['impressum', 'datenschutz'] as $key) {
                if (!isset($_POST['legal'][$key])) {
                    continue;
                }
                $content['legal'][$key] = $this->normalizeData(self::LEGAL_FIELDS, $_POST['legal'][$key], "legal[{$key}]");
            }
        }

        CMS::writeJson($path, $content);
        $message = 'Gespeichert.';
        if ($renameErrors !== []) {
            $message .= ' Anker nicht umbenannt: ' . implode(', ', $renameErrors) . '.';
        }
        $this->redirectToEdited($message, $this->focusPanel($idMap));
    }

    /**
     * "Testmail senden" (Betrieb / Kontaktdaten): derselbe Versandweg wie das
     * Kontaktformular (Core\Mail), an die gespeicherte Betriebs-E-Mail. Ein
     * true von mail() heißt nur "vom Server angenommen" – ob sie ankommt, zeigt
     * erst das Postfach (Spam-Ordner!), deshalb der Hinweis im Flash.
     */
    private function sendTestMail(): void
    {
        $content = $this->cms->content();
        $to = Mail::businessEmail($content);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->redirectToPanel('Keine gültige E-Mail unter Betrieb / Kontaktdaten gespeichert – Testmail nicht gesendet.', 'panel-business');
        }
        $from = Mail::fromAddress($this->cms->config(), $content);
        $ok = Mail::send(
            $this->cms->config(),
            $content,
            $to,
            'Testmail von ' . ($_SERVER['HTTP_HOST'] ?? 'der Website'),
            "Diese Testmail wurde über „Testmail senden“ im Admin verschickt (" . date('d.m.Y H:i') . ").\n\n"
                . "Kommt sie an, funktioniert auch der Versand des Kontaktformulars.\n"
                . "Absender: {$from}\n"
        );
        $this->redirectToPanel($ok
            ? "Testmail an {$to} vom Server angenommen (Absender {$from}). Bitte Postfach prüfen – auch den Spam-Ordner. Landet sie dort oder gar nicht, in data/config.json \"mail\": {\"from\": \"…\"} auf eine Adresse der Webhoster-Domain setzen."
            : "Der Server hat die Testmail an {$to} abgelehnt (mail() fehlgeschlagen) – beim Webhoster prüfen, ob PHP-mail() aktiviert ist.", 'panel-business');
    }

    /** Panel "Anfragen": eine Einsendung löschen (auch für Bearbeiter – sie betreuen die Anfragen). */
    private function deleteInquiry(): void
    {
        $id = (string) ($_POST['id'] ?? '');
        $deleted = $id !== '' && Inquiries::delete($this->cms->root(), $id);
        $this->redirectToPanel($deleted ? 'Anfrage gelöscht.' : 'Anfrage nicht gefunden.', 'panel-inquiries');
    }

    private function deleteAllInquiries(): void
    {
        $count = Inquiries::deleteAll($this->cms->root());
        $this->redirectToPanel($count . ' Anfrage' . ($count === 1 ? '' : 'n') . ' gelöscht.', 'panel-inquiries');
    }

    /** Prüfsumme von data/content.json – wechselt mit jedem Speichern, egal von wem. */

    private function contentRevision(): string
    {
        $hash = @hash_file('sha256', $this->cms->root() . '/data/content.json');

        return $hash === false ? '' : $hash;
    }

    /**
     * Speicher-Konflikt: die eigenen Eingaben gehen nicht verloren, sondern
     * stehen als versteckte Felder in einem neuen Formular – "Trotzdem
     * speichern" schickt sie mit der aktuellen Revision erneut ab (überschreibt
     * dann bewusst), "Verwerfen" lädt den Stand des anderen. Neu gewählte
     * Bild-Dateien kann ein Formular nicht erneut mitschicken – darauf weist
     * die Seite hin.
     */
    private function renderSaveConflict(): void
    {
        $fields = [];
        $flatten = static function (array $data, string $prefix) use (&$flatten, &$fields): void {
            foreach ($data as $key => $value) {
                $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
                if (is_array($value)) {
                    $flatten($value, $name);
                } else {
                    $fields[] = ['name' => $name, 'value' => (string) $value];
                }
            }
        };
        $post = $_POST;
        unset($post['content_rev'], $post['csrf']);
        $flatten($post, '');

        $hasFiles = false;
        array_walk_recursive($_FILES, static function ($v, $k) use (&$hasFiles) {
            if ($k === 'error' && (int) $v === UPLOAD_ERR_OK) {
                $hasFiles = true;
            }
        });

        http_response_code(409);
        $content = CMS::readJson($this->cms->root() . '/data/content.json');
        echo $this->cms->twig()->render('admin-conflict.twig', [
            'fields' => $fields,
            'csrf' => $_SESSION['csrf'] ?? '',
            'content_rev' => $this->contentRevision(),
            'had_files' => $hasFiles,
            'site_name' => $this->displayString($content['site']['title'] ?? '', 'CMS'),
        ]);
    }

    /**
     * Panel, das das Content-Formular beim Speichern als "hier war ich" meldet
     * (hidden focus_panel, per JS aus dem sichtbaren Panel gefüllt). Geprüft wird
     * nur das Format – der Wert landet unverändert im Location-Fragment; eine
     * Abfrage gegen eine feste Panel-Liste müsste jedes neue Panel dauerhaft
     * mitführen. Eine Anker-Umbenennung im selben Durchgang zieht $idMap mit,
     * sonst wäre der Sprung genau nach dem Umbenennen ins Leere gerichtet;
     * unbekannte Werte bleiben folgenlos (admin.twig fällt auf panel-site zurück).
     *
     * @param array<string, string> $idMap
     */
    private function focusPanel(array $idMap): string
    {
        $raw = trim((string) ($_POST['focus_panel'] ?? ''));
        if (!preg_match('/^panel-[a-z][a-z0-9-]{0,59}$/', $raw)) {
            return '';
        }
        $slug = substr($raw, strlen('panel-'));

        return 'panel-' . ($idMap[$slug] ?? $slug);
    }

    /**
     * Nach dem Content-Speichern zurück zur bearbeiteten Stelle: Fragment für
     * das zuletzt offene Panel (ein Redirect ohne Fragment lässt den Browser je
     * nach Version am alten hängen oder fällt auf panel-site zurück) und
     * focus_scroll mit der Scroll-Position, das admin.twig nach dem Reload
     * wiederherstellt.
     */
    private function redirectToEdited(string $message, string $panel): never
    {
        $_SESSION['flash'] = $message;
        $scroll = max(0, min(200000, (int) ($_POST['focus_scroll'] ?? 0)));
        $url = '?admin=1';
        if ($scroll > 0) {
            $url .= '&focus_scroll=' . $scroll;
        }
        if ($panel !== '') {
            $url .= '#' . $panel;
        }
        header('Location: ' . $url);
        exit;
    }

    private function addSection(): void
    {
        $type = (string) ($_POST['type'] ?? '');
        $schema = $this->schemaFor($type);
        if ($schema === []) {
            $this->redirect('Unbekannte Komponente.');
        }

        $path = $this->cms->root() . '/data/content.json';
        $content = CMS::readJson($path);
        $sections = $content['sections'] ?? [];
        $maxSort = 0;
        $existingIds = [];
        foreach ($sections as $section) {
            $maxSort = max($maxSort, (int) ($section['sort'] ?? 0));
            $existingIds[(string) ($section['id'] ?? '')] = true;
        }

        // Lesbare ID statt Zufalls-Hex: "<type>-<n>", n hochgezählt bis frei -
        // gleiches Muster wie die von Hand angelegten Beispiel-Sections
        // (content-block-1, faq-1, ...), damit Anker im Admin (Section-Picker,
        // Nav-Links) erkennbar bleiben statt kryptischer Zeichenfolgen.
        $n = 1;
        do {
            $id = $type . '-' . $n;
            $n++;
        } while (isset($existingIds[$id]));

        $sections[] = [
            'id' => $id,
            'type' => $type,
            'sort' => $maxSort + 10,
            'label' => '',
            'group' => '',
            'data' => $this->emptyFromSchema($schema['fields'] ?? []),
        ];
        $content['sections'] = $sections;
        CMS::writeJson($path, $content);
        $this->redirect('Sektion hinzugefügt.');
    }

    private function deleteSection(): void
    {
        $id = (string) ($_POST['id'] ?? '');
        $path = $this->cms->root() . '/data/content.json';
        $content = CMS::readJson($path);
        $content['sections'] = array_values(array_filter(
            $content['sections'] ?? [],
            static fn ($s) => (string) ($s['id'] ?? '') !== $id
        ));
        CMS::writeJson($path, $content);
        $this->redirect('Sektion entfernt.');
    }

    /**
     * Sofort-Speichern der Section-Reihenfolge (Sidebar-Drag&Drop/Pfeile) – eigener
     * AJAX-Endpunkt statt des großen content-form-Submits, damit Sortieren nicht
     * erst über "Änderungen speichern" persistiert werden muss.
     */
    private function reorderSections(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $order = $_POST['order'] ?? [];
        if (!is_array($order)) {
            echo json_encode(['ok' => false, 'message' => 'Ungültige Daten.']);
            exit;
        }

        $path = $this->cms->root() . '/data/content.json';
        $content = CMS::readJson($path);
        $sections = is_array($content['sections'] ?? null) ? $content['sections'] : [];

        $byId = [];
        foreach ($sections as $s) {
            if (is_array($s) && isset($s['id'])) {
                $byId[(string) $s['id']] = $s;
            }
        }

        $reordered = [];
        $sort = 10;
        foreach ($order as $id) {
            $id = (string) $id;
            if (isset($byId[$id])) {
                $byId[$id]['sort'] = $sort;
                $reordered[] = $byId[$id];
                unset($byId[$id]);
                $sort += 10;
            }
        }
        // Sicherheitsnetz: nicht mitgeschickte/unbekannte IDs hinten anhängen statt zu verlieren.
        foreach ($byId as $rest) {
            $rest['sort'] = $sort;
            $reordered[] = $rest;
            $sort += 10;
        }

        $content['sections'] = $reordered;
        CMS::writeJson($path, $content);
        // Neue Revision zurück, damit das offene Content-Formular sie übernimmt –
        // sonst hielte saveContent() das eigene Sortieren für einen fremden Speichervorgang.
        echo json_encode(['ok' => true, 'content_rev' => $this->contentRevision()]);
        exit;
    }

    private const KEEP_CODE_BACKUPS = 3;
    /** Slug des Beispiel-Projekts (dev/example-project/) im Projekt-Import. */
    private const EXAMPLE_PROJECT = 'beispiel';

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function componentsDir(): string
    {
        return $this->cms->root() . '/themes-and-plugins/components';
    }

    /**
     * true nur im Entwickler-Checkout (dieses Repo), false auf jeder deployten/
     * exportierten Instanz: dev/ liegt laut docs/DEPLOY.md nie in einem Export
     * oder echten Deploy (nur lokal, außerhalb von web/) - genau der Ordner, der
     * "Update-Paket erstellen"/"Components-Update-Paket erstellen" überhaupt
     * benutzbar macht (dev/build-release.php). Blendet das Paket-*Bauen* dort
     * aus, wo es nie sinnvoll ist: eine Kunden-Instanz *empfängt* Updates
     * ("Update prüfen"/"aktualisieren" bleiben immer sichtbar), sie *baut*
     * nie welche.
     */
    private function hasDevTools(): bool
    {
        // basename === "web" zusätzlich zur dev/-Prüfung: eine exportierte/deployte
        // Instanz hat index.php direkt im eigenen Root (kein web/-Unterordner, siehe
        // docs/DEPLOY.md) - dort würde dirname(root) sonst eine Ebene zu hoch (den
        // Ordner ÜBER der Instanz) prüfen statt "kein dev/-Geschwister vorhanden".
        $root = $this->cms->root();
        return basename($root) === 'web' && is_dir(dirname($root) . '/dev');
    }

    /** @return list<array<string, mixed>> */
    public function scanSchemas(): array
    {
        $dir = $this->cms->root() . '/themes-and-plugins/components';
        $out = [];
        foreach (glob($dir . '/*/schema.json') ?: [] as $file) {
            $schema = CMS::readJson($file);
            if ($schema !== []) {
                $out[] = $schema;
            }
        }
        return $out;
    }

    /**
     * Verfügbare Theme-Bündel (Marken-Farben/Fonts + Header-/Footer-/Topbar-/
     * Sticky-Bar-Variante als ein Paket) für den Theme-Umschalter im Admin.
     *
     * @return list<array<string, mixed>>
     */
    public function scanThemes(): array
    {
        $dir = $this->cms->root() . '/themes-and-plugins/themes';
        $out = [];
        foreach (glob($dir . '/*/theme.json') ?: [] as $file) {
            $theme = CMS::readJson($file);
            if ($theme !== [] && ($theme['name'] ?? '') !== '') {
                $out[] = $theme;
            }
        }
        return $out;
    }

    /**
     * Alle verfügbaren Varianten einer Layout-Region (Header/Footer/Topbar/
     * Sticky-Bar/Cookie-Banner) im gemeinsamen Pool, für den Regionen-Picker
     * unter Design (siehe Admin::buildTheme()).
     *
     * @return list<array{slug: string, label: string}>
     */
    private function scanRegionVariants(string $region): array
    {
        $root = $this->cms->root();
        $out = [];
        $seen = [];
        foreach (glob($root . "/themes-and-plugins/{$region}s/*/schema.json") ?: [] as $file) {
            $schema = CMS::readJson($file);
            $slug = basename(dirname($file));
            $out[] = ['slug' => $slug, 'label' => (string) ($schema['label'] ?? $slug)];
            $seen[$slug] = true;
        }

        // Auf einer schlanken Instanz (Theme-Kopie ohne mitgelieferten Pool,
        // siehe exportProjectInstance()) stünde die aktuell verwendete Variante
        // sonst nicht zur Auswahl: Der Picker wäre leer und der Slug fälschlich
        // als "— Keine —" markiert, obwohl CMS::renderRegion() die Theme-Kopie
        // als Fallback tatsächlich rendert. Liegt die Variante im Pool (der
        // Vorrang hat), greift der glob oben ohnehin.
        $config = $this->cms->config();
        $theme = (string) ($config['theme'] ?? '');
        $currentSlug = $this->resolveRegionVariant($config, $region);
        if ($theme !== '' && $currentSlug !== null && !isset($seen[$currentSlug])) {
            $themeRegionFile = $root . "/themes-and-plugins/themes/{$theme}/{$region}/schema.json";
            if (is_file($themeRegionFile)) {
                $schema = CMS::readJson($themeRegionFile);
                $out[] = ['slug' => $currentSlug, 'label' => (string) ($schema['label'] ?? $currentSlug)];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function schemaFor(string $type): array
    {
        foreach ($this->scanSchemas() as $schema) {
            if (($schema['type'] ?? '') === $type) {
                return $schema;
            }
        }
        return [];
    }

    /**
     * Schema der aktuell in config.json aktiven Header-/Footer-/Topbar-Variante
     * (Layout-Regionen sind Components wie im themes-and-plugins/components-Pool,
     * nur singulär statt wiederholbar – ihr Content-Feld-Set kommt genauso aus
     * einem schema.json neben ihrem template.twig).
     *
     * @return array<string, mixed>
     */
    private function layoutSchemaFor(string $region): array
    {
        $config = $this->cms->config();
        $layout = $config['layout'] ?? [];
        $defaults = ['header' => 'standard-nav', 'footer' => 'simple-footer', 'topbar' => 'standard', 'stickybar' => 'icon-rail', 'cookiebanner' => 'corner'];
        $variant = $region === 'topbar'
            ? (string) ($layout['topbar']['theme'] ?? $defaults['topbar'])
            : (string) ($layout[$region] ?? $defaults[$region] ?? '');

        $path = $this->cms->root() . "/themes-and-plugins/{$region}s/{$variant}/schema.json";
        if (is_file($path)) {
            return CMS::readJson($path);
        }

        // Gleicher Fall wie in scanRegionVariants(): ein schlanker Export
        // (aktives Theme mit eigener Region-Kopie, siehe
        // exportProjectInstance()) hat den gemeinsamen Pool oben gar nicht
        // - ohne diesen Fallback wäre auf so einer Instanz das komplette
        // Content-Formular dieser Region (Texte, Buttons, ggf. der
        // An/Aus-Schalter) unsichtbar, obwohl CMS::renderRegion() sie
        // anzeigt und Inhalte dafür in content.json längst existieren.
        $theme = (string) ($config['theme'] ?? '');
        if ($theme !== '') {
            $themeRegionFile = $this->cms->root() . "/themes-and-plugins/themes/{$theme}/{$region}/schema.json";
            if (is_file($themeRegionFile)) {
                return CMS::readJson($themeRegionFile);
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function emptyFromSchema(array $fields): array
    {
        $data = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $data[$name] = ($field['type'] ?? '') === 'repeater' ? [] : '';
        }
        return $data;
    }

    /**
     * Löst einen Wert, der eine Sprach-Map sein kann ({"de": "...", "fr": "..."}),
     * für reine Anzeige-Zwecke im Admin (z. B. der Seitenname im Header) zu
     * einem einzelnen String auf – "de" bevorzugt, sonst der erste Wert.
     * Nicht für editierbare Felder (die brauchen die volle Map, siehe
     * admin-field.twig).
     */
    private function displayString(mixed $value, string $fallback = ''): string
    {
        if (is_array($value)) {
            $value = $value['de'] ?? reset($value) ?: '';
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    /**
     * Normalisiert ein Formularfeld, das mehrsprachig sein kann: kommt es als
     * Array an (name="feld[de]"/"feld[fr]" – siehe admin-field.twig), wird
     * jede Sprache einzeln getrimmt; kommt es als einzelner String an
     * (Einsprachigkeit oder kein POST-Wert für dieses Feld), bleibt das
     * Verhalten wie vor der Mehrsprachigkeit. $existing ist der bisherige
     * Wert (String oder Sprach-Array), Fallback wenn $posted null ist.
     *
     * @return string|array<string, string>
     */
    private function translatableValue(mixed $posted, mixed $existing): string|array
    {
        if (is_array($posted)) {
            return array_map(static fn ($v) => trim((string) $v), $posted);
        }
        if ($posted !== null) {
            return trim((string) $posted);
        }

        return is_array($existing) ? $existing : trim((string) ($existing ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function normalizeData(array $fields, mixed $posted, string $filePrefix): array
    {
        $posted = is_array($posted) ? $posted : [];
        $out = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ($name === '') {
                continue;
            }
            if ($type === 'repeater') {
                $rows = $posted[$name] ?? [];
                $out[$name] = [];
                if (is_array($rows)) {
                    foreach ($rows as $i => $row) {
                        $rowData = $this->normalizeData(
                            $field['fields'] ?? [],
                            $row,
                            $filePrefix . '[' . $name . '][' . $i . ']'
                        );
                        $rowData['active'] = !is_array($row) || ($row['active'] ?? '1') !== '0';
                        $out[$name][] = $rowData;
                    }
                }
                continue;
            }
            if ($type === 'image') {
                $out[$name] = $this->handleUpload($filePrefix . '[' . $name . ']', (string) ($posted[$name] ?? ''));
                // Optionales Größen-Preset am Bildfeld ("size": true im Schema):
                // wird als Schwester-Feld "<name>_size" neben den Bildpfad gespeichert.
                if (($field['size'] ?? false)) {
                    $size = (string) ($posted[$name . '_size'] ?? 'auto');
                    $out[$name . '_size'] = in_array($size, ['auto', 'small', 'medium', 'full'], true) ? $size : 'auto';
                }
                continue;
            }
            if ($type === 'number' || $type === 'rating') {
                $out[$name] = (int) ($posted[$name] ?? 0);
                continue;
            }
            if ($type === 'icon') {
                $out[$name] = $this->normalizeIconValue((string) ($posted[$name] ?? ''));
                continue;
            }
            if ($type === 'checkbox') {
                // Ungecheckte Boxen fehlen im POST komplett - admin-field.twig
                // rendert deshalb immer ein hidden value="0" davor, damit hier
                // zuverlässig "0" statt gar nichts ankommt.
                $out[$name] = ($posted[$name] ?? '0') === '1';
                continue;
            }
            // Mehrsprachiges text/textarea-Feld: name="...[de]"/"...[fr]" ->
            // {"de": "...", "fr": "..."}, aufgelöst erst beim Public-Render
            // (siehe CMS::localizeContent()) – translatableValue() erkennt das
            // automatisch am POST-Wert (Array statt String).
            $out[$name] = $this->translatableValue($posted[$name] ?? null, '');
        }
        return $out;
    }

    /**
     * Feldtyp "icon": entweder ein Pool-Slug (admin-field.twig prüft dafür
     * nichts weiter, unbekannte Slugs rendern in Icons::render() einfach
     * nichts) oder ein frei eingegebener Pfad mit Icons::CUSTOM_PREFIX -
     * dessen Pfaddaten laufen zwingend durch Icons::isValidCustomPath(),
     * bevor sie gespeichert werden. Ohne diese Prüfung hier könnte man den
     * Feldwert auch direkt per POST setzen (am UI vorbei) und damit
     * ungültige/gefährliche Zeichen in content.json bringen, die dann bei
     * jedem Seitenaufruf über {{ icon(...)|raw }} landen würden.
     */
    private function normalizeIconValue(string $value): string
    {
        $value = trim($value);
        if (!str_starts_with($value, Icons::CUSTOM_PREFIX)) {
            return $value;
        }

        $path = substr($value, strlen(Icons::CUSTOM_PREFIX));
        return Icons::isValidCustomPath($path) ? $value : '';
    }

    /**
     * Wendet ein Theme-Bündel (themes-and-plugins/themes/<name>/theme.json) an:
     * überschreibt brand.* sowie die Header-/Footer-/Sticky-Bar-Variante und die
     * Topbar-Theme-Variante in config.json (topbar.enabled bleibt unangetastet –
     * das ist eine separate Ein/Aus-Entscheidung, kein Teil des Themes). Merkt sich
     * den zuletzt angewendeten Namen in config.theme, nur zur Anzeige im Admin.
     */
    private function applyTheme(): void
    {
        $name = trim((string) ($_POST['theme'] ?? ''));
        $theme = null;
        foreach ($this->scanThemes() as $candidate) {
            if (($candidate['name'] ?? '') === $name) {
                $theme = $candidate;
                break;
            }
        }
        if ($theme === null) {
            $this->redirectToPanel('Theme nicht gefunden.', 'panel-theme');
        }

        $path = $this->cms->root() . '/data/config.json';
        $config = CMS::readJson($path);

        // Ersetzt das komplette brand, statt es mit dem bisherigen zu mergen:
        // sonst würden Schlüssel, die das neue Theme gar nicht setzt (z. B.
        // hero_cta), vom vorher aktiven Theme übrig bleiben. Fehlende
        // Schlüssel fängt layout.twig ohnehin über |default(...) ab.
        $config['brand'] = $theme['brand'] ?? [];
        $themeLayout = $theme['layout'] ?? [];
        foreach (['header', 'footer', 'stickybar', 'cookiebanner'] as $region) {
            if (isset($themeLayout[$region])) {
                $config['layout'][$region] = $themeLayout[$region];
            }
        }
        if (isset($themeLayout['topbar'])) {
            $config['layout']['topbar']['theme'] = $themeLayout['topbar'];
        }
        // Sprachen gehören zum Projekt: ist ein Projekt geladen (dev_import), bleiben
        // dessen Sprachen unangetastet (z. B. doro01 de/en/fr trotz Theme de/en/fr/lb).
        // Nur ohne geladenes Projekt bestimmt das Theme den Sprachsatz.
        if (isset($theme['languages']) && trim((string) ($config['dev_import'] ?? '')) === '') {
            $themeLangs = array_values(array_filter(
                array_map('strval', $theme['languages']),
                static fn (string $l): bool => $l !== ''
            ));

            // Das Theme definiert den Sprachsatz des Projekts: mitgebrachte
            // Basis-Sprachen wieder freigeben, nicht benötigte Basis-Sprachen
            // ausblenden (config.languages_disabled), sobald das Theme sie
            // nicht mitbringt. Ein Wechsel auf ein rein deutschsprachiges
            // Theme blendet damit automatisch die Sprachen des Vorgänger-Themes
            // aus – sie schleppen sich nicht weiter mit. Projekteigene
            // Ergänzungen aus config.languages_allowed sind nicht betroffen.
            $config = $this->syncThemeLanguages($themeLangs, $config);
            $config['languages'] = $this->normalizeLanguages($themeLangs, $config);
        }
        $config['theme'] = $name;

        CMS::writeJson($path, $config);
        unset($_SESSION['theme_preview']);
        $this->rrmdir($this->cms->root() . '/cache/pages');

        $target = $this->assignThemeToProject($config, $name);
        $this->redirectToPanel('Theme „' . ($theme['label'] ?? $name) . '“ '
            . ($target !== '' ? 'dem Projekt zugewiesen – eingetragen in ' . $target . '.' : 'angewendet.'), 'panel-theme');
    }

    /**
     * Schreibt eine Theme-Zuweisung in die Daten des geladenen Projekts: lokale
     * Instanz (data/config.json), sonst dev-imports/<slug>/config.json bzw. das
     * Beispiel-Projekt – damit ein späteres Laden (und bei Instanzen der nächste
     * Sync/Deploy) das neue Theme mitbringt. Gesetzt werden nur theme, brand und
     * die Varianten; topbar.enabled (Inhalt), Sprachen und alles andere bleiben.
     *
     * @return string Ziel für die Meldung, '' wenn kein Projekt geladen ist
     */
    private function assignThemeToProject(array $generatorConfig, string $themeName): string
    {
        $slug = trim((string) ($generatorConfig['dev_import'] ?? ''));
        if ($slug === '' || !$this->hasDevTools()) {
            return '';
        }
        foreach ($this->scanDevImports() as $project) {
            if ($project['slug'] !== $slug) {
                continue;
            }
            $file = $project['data_dir'] . '/config.json';
            $target = CMS::readJson($file);
            $target['theme'] = $themeName;
            $target['brand'] = $generatorConfig['brand'] ?? [];
            foreach (['header', 'footer', 'stickybar', 'cookiebanner'] as $region) {
                if (isset($generatorConfig['layout'][$region])) {
                    $target['layout'][$region] = $generatorConfig['layout'][$region];
                }
            }
            $topbarTheme = $generatorConfig['layout']['topbar']['theme'] ?? null;
            if ($topbarTheme !== null) {
                $enabled = (bool) ($target['layout']['topbar']['enabled'] ?? ($generatorConfig['layout']['topbar']['enabled'] ?? false));
                $target['layout']['topbar'] = ['enabled' => $enabled, 'theme' => (string) $topbarTheme];
            }
            CMS::writeJson($file, $target);

            return $project['source'] === 'instanz'
                ? $project['source_label'] . ' (Varianten/Theme-Dateien kommen mit dev/deploy-instances.sh nach)'
                : $project['source_label'];
        }

        return '';
    }

    /** Theme-Vorschau (nur Generator): die öffentliche Seite zeigt das Theme in dieser Sitzung – nichts wird gespeichert. */
    private function startThemePreview(): void
    {
        $name = trim((string) ($_POST['theme'] ?? ''));
        foreach ($this->scanThemes() as $candidate) {
            if (($candidate['name'] ?? '') === $name) {
                $_SESSION['theme_preview'] = $name;
                $this->redirectToPanel('Vorschau aktiv: „' . ($candidate['label'] ?? $name) . '“ – unter „Seite“ ansehen. Gespeichert wird nichts.', 'panel-theme');
            }
        }
        $this->redirectToPanel('Theme nicht gefunden.', 'panel-theme');
    }

    private function endThemePreview(): void
    {
        unset($_SESSION['theme_preview']);
        $this->redirectToPanel('Vorschau beendet – die Seite zeigt wieder das zugewiesene Theme.', 'panel-theme');
    }

    /**
     * Topbar ist die einzige Region mit einem echten An/Aus-Zustand
     * (layout.topbar.enabled) statt nur einer Varianten-Auswahl - andere
     * Regionen sind implizit immer "an", sobald eine Variante gewählt ist
     * (siehe resolveRegionVariant()). "theme" bleibt beim erstmaligen
     * Aktivieren nicht leer, damit layoutSchemaFor('topbar') sofort ein
     * Schema findet, auch wenn noch nie eine Variante gewählt wurde.
     */
    private function toggleTopbar(): void
    {
        $path = $this->cms->root() . '/data/config.json';
        $config = CMS::readJson($path);
        $enabled = !(bool) ($config['layout']['topbar']['enabled'] ?? false);
        $config['layout']['topbar']['enabled'] = $enabled;
        if (trim((string) ($config['layout']['topbar']['theme'] ?? '')) === '') {
            $config['layout']['topbar']['theme'] = 'standard';
        }
        CMS::writeJson($path, $config);
        $this->redirectToPanel($enabled ? 'Topbar aktiviert.' : 'Topbar deaktiviert.', 'panel-site');
    }

    /**
     * Farbset des aktiven Themes wählen (config.palette, leer = Standard). Wie die
     * Topbar ein Inhaltsschalter: bewusst NICHT in der Layout-Sperre, damit der
     * Kunde im Admin seiner Website umschalten kann; die Sets selbst stehen in der
     * theme.json und werden nur im Generator gepflegt.
     */
    private function setPalette(): void
    {
        $path = $this->cms->root() . '/data/config.json';
        $config = CMS::readJson($path);
        $theme = CMS::readJson($this->cms->root() . '/themes-and-plugins/themes/' . basename((string) ($config['theme'] ?? '')) . '/theme.json');
        $palettes = CMS::themePalettes($this->cms->root(), $theme);
        $choice = trim((string) ($_POST['palette'] ?? ''));
        if ($choice !== '' && !isset($palettes[$choice])) {
            $this->redirectToPanel('Dieses Farbset gibt es im aktiven Theme nicht.', 'panel-site');
        }
        if ($choice === '') {
            unset($config['palette']);
        } else {
            $config['palette'] = $choice;
        }
        CMS::writeJson($path, $config);
        $label = $choice === '' ? 'Standard' : (string) ($palettes[$choice]['label'] ?? $choice);
        $this->redirectToPanel('Farbvariante „' . $label . '“ aktiv.', 'panel-site');
    }

    /**
     * Speichert die zentrale Farbset-Bibliothek (themes-and-plugins/palettes/<slug>.json,
     * nur im Generator). Bestehende Sets behalten ihren Slug auch beim Umbenennen
     * (Themes verweisen darüber); entfernt wird nur, was kein Theme mehr anbietet.
     */
    private function savePalettePool(): void
    {
        $dir = $this->cms->root() . CMS::PALETTE_DIR;
        $existing = CMS::poolPalettes($this->cms->root());
        $sets = [];
        foreach (is_array($_POST['pool'] ?? null) ? $_POST['pool'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            if (!isset($existing[$slug])) {
                $slug = CMS::slugify($label);
            }
            if (mb_strlen($label) > 30 || $slug === '' || $slug === 'standard' || isset($sets[$slug])) {
                $this->redirectToPanel('Ungültiger oder doppelter Farbset-Name: „' . $label . '“ – nichts gespeichert.', 'panel-theme');
            }
            $set = ['label' => $label];
            foreach (CMS::PALETTE_COLORS as $key) {
                $color = trim((string) ($row[$key] ?? ''));
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
                    $this->redirectToPanel('Farbset „' . $label . '“: ungültige Farbe für ' . $key . ' – nichts gespeichert.', 'panel-theme');
                }
                $set[$key] = $color;
            }
            $sets[$slug] = $set;
        }
        // Noch von einem Theme angebotene Sets nicht löschen
        foreach (array_diff(array_keys($existing), array_keys($sets)) as $removed) {
            $users = [];
            foreach ($this->scanThemes() as $theme) {
                if (in_array($removed, (array) ($theme['palette_refs'] ?? []), true)) {
                    $users[] = (string) ($theme['label'] ?? $theme['name'] ?? '');
                }
            }
            if ($users !== []) {
                $this->redirectToPanel('Farbset „' . ($existing[$removed]['label'] ?? $removed) . '“ wird noch angeboten von: ' . implode(', ', $users) . ' – dort erst abwählen, nichts gespeichert.', 'panel-theme');
            }
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->redirectToPanel('Ordner für die Farbset-Bibliothek konnte nicht angelegt werden.', 'panel-theme');
        }
        foreach ($sets as $slug => $set) {
            CMS::writeJson("{$dir}/{$slug}.json", $set);
        }
        foreach (array_diff(array_keys($existing), array_keys($sets)) as $removed) {
            @unlink("{$dir}/{$removed}.json");
        }
        $this->redirectToPanel('Farbset-Bibliothek gespeichert (' . count($sets) . ' Sets).', 'panel-theme');
    }

    /**
     * Baut ein neues, eigenständiges Theme aus Region-Varianten, die im Admin
     * einzeln gewählt wurden (Header/Footer/Topbar/Sticky-Bar/Cookie-Banner).
     * Kopiert die gewählten template.twig/schema.json-Dateien aus dem
     * gemeinsamen Pool (themes-and-plugins/<region>s/<variante>/) in einen
     * neuen, eigenen Ordner (themes-and-plugins/themes/<slug>/<region>/) –
     * damit ist das Theme danach ein in sich geschlossenes, portables Paket
     * (z. B. für ein anderes Projekt kopierbar), unabhängig vom Pool. Ein
     * `_source`-Feld in der kopierten schema.json hält fest, aus welcher
     * Pool-Variante kopiert wurde – rein informativ, kein Auto-Sync.
     */
    private function buildTheme(): void
    {
        $label = trim((string) ($_POST['theme_label'] ?? ''));
        if ($label === '') {
            $this->redirectToPanel('Bitte einen Namen für das Theme angeben.', 'panel-theme');
        }
        $slug = CMS::slugify($label);
        if ($slug === '') {
            $this->redirectToPanel('Ungültiger Theme-Name.', 'panel-theme');
        }

        $regions = ['header', 'footer', 'topbar', 'stickybar', 'cookiebanner'];
        $chosen = [];
        foreach ($regions as $region) {
            $variant = trim((string) ($_POST['regions'][$region] ?? ''));
            $available = array_column($this->scanRegionVariants($region), 'slug');
            if ($variant !== '' && !in_array($variant, $available, true)) {
                $this->redirectToPanel('Ungültige Auswahl für ' . $region . '.', 'panel-theme');
            }
            $chosen[$region] = $variant;
        }

        // Farben/Schriften/Radius aus dem Formular prüfen, bevor irgendetwas angelegt wird; später über
        // das bisherige brand gelegt – übrige brand-
        // Schlüssel (hero_cta, zebra_accent, …) bleiben erhalten, leere Felder
        // lassen den bisherigen Wert stehen. Validiert, weil die Werte ungefiltert
        // in CSS-Variablen und die Google-Fonts-URL (layout.twig) gehen.
        $brandRules = [
            'primary' => '/^#[0-9a-fA-F]{6}$/', 'secondary' => '/^#[0-9a-fA-F]{6}$/',
            'ash' => '/^#[0-9a-fA-F]{6}$/', 'walnut' => '/^#[0-9a-fA-F]{6}$/',
            'font_display' => '/^[A-Za-z0-9 ]{1,40}$/', 'font_body' => '/^[A-Za-z0-9 ]{1,40}$/',
            'radius' => '/^(0|\d+(\.\d+)?(rem|px|em))$/',
        ];
        $brandOverrides = [];
        $postedBrand = is_array($_POST['brand'] ?? null) ? $_POST['brand'] : [];
        foreach ($brandRules as $key => $pattern) {
            $value = trim((string) ($postedBrand[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (preg_match($pattern, $value) !== 1) {
                $this->redirectToPanel('Ungültiger Wert für ' . $key . ': „' . $value . '“ – nichts gespeichert.', 'panel-theme');
            }
            $brandOverrides[$key] = $value;
        }
        // Weitere Farbsets (theme.json → palettes): Name + die vier Farben je Zeile,
        // leere Zeilen werden ignoriert. Die Auswahl trifft der Admin der Website
        // (setPalette()), gepflegt werden die Sets nur hier.
        $palettes = [];
        foreach (is_array($_POST['palettes'] ?? null) ? $_POST['palettes'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $paletteLabel = trim((string) ($row['label'] ?? ''));
            if ($paletteLabel === '') {
                continue;
            }
            $paletteSlug = CMS::slugify($paletteLabel);
            if (mb_strlen($paletteLabel) > 30 || $paletteSlug === '' || $paletteSlug === 'standard' || isset($palettes[$paletteSlug])) {
                $this->redirectToPanel('Ungültiger oder doppelter Farbset-Name: „' . $paletteLabel . '“ – nichts gespeichert.', 'panel-theme');
            }
            $set = ['label' => $paletteLabel];
            foreach (CMS::PALETTE_COLORS as $key) {
                $color = trim((string) ($row[$key] ?? ''));
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
                    $this->redirectToPanel('Farbset „' . $paletteLabel . '“: ungültige Farbe für ' . $key . ' – nichts gespeichert.', 'panel-theme');
                }
                $set[$key] = $color;
            }
            $palettes[$paletteSlug] = $set;
        }
        // Aus der Bibliothek angebotene Sets (theme.json → palette_refs)
        $pool = CMS::poolPalettes($this->cms->root());
        $paletteRefs = [];
        foreach (is_array($_POST['palette_refs'] ?? null) ? $_POST['palette_refs'] : [] as $ref) {
            $ref = (string) $ref;
            if (!isset($pool[$ref])) {
                $this->redirectToPanel('Farbset „' . $ref . '“ gibt es in der Bibliothek nicht – nichts gespeichert.', 'panel-theme');
            }
            if (isset($palettes[$ref])) {
                $this->redirectToPanel('Eigenes Farbset „' . $palettes[$ref]['label'] . '“ heißt wie ein Bibliotheks-Set – bitte umbenennen, nichts gespeichert.', 'panel-theme');
            }
            $paletteRefs[] = $ref;
        }

        $root = $this->cms->root();
        $themeDir = $root . "/themes-and-plugins/themes/{$slug}";
        if (!is_dir($themeDir) && !mkdir($themeDir, 0775, true) && !is_dir($themeDir)) {
            $this->redirectToPanel('Theme-Ordner konnte nicht angelegt werden.', 'panel-theme');
        }

        foreach ($chosen as $region => $variant) {
            $dstDir = "{$themeDir}/{$region}";
            if ($variant === '') {
                // "— Keine —" gewählt: eine evtl. vorhandene eigene Kopie aus
                // einem früheren Speichern muss weg, sonst rendert renderRegion()
                // sie als Fallback (der Pool-Pfad ist leer) und "Keine" hätte
                // keine Wirkung.
                if (is_dir($dstDir)) {
                    $this->rrmdir($dstDir);
                }
                continue;
            }
            $srcDir = $root . "/themes-and-plugins/{$region}s/{$variant}";
            if (!is_dir($dstDir) && !mkdir($dstDir, 0775, true) && !is_dir($dstDir)) {
                $this->redirectToPanel('Region-Ordner konnte nicht angelegt werden: ' . $region, 'panel-theme');
            }
            foreach (['template.twig', 'schema.json'] as $file) {
                $srcFile = "{$srcDir}/{$file}";
                if (!is_file($srcFile)) {
                    continue;
                }
                if ($file === 'schema.json') {
                    $schema = CMS::readJson($srcFile);
                    $schema['_source'] = "{$region}s/{$variant} (kopiert " . date('Y-m-d') . ')';
                    CMS::writeJson("{$dstDir}/{$file}", $schema);
                } else {
                    copy($srcFile, "{$dstDir}/{$file}");
                }
            }
        }

        $configPath = $root . '/data/config.json';
        $config = CMS::readJson($configPath);
        $languages = $this->normalizeLanguages($_POST['languages'] ?? []);

        // Wird ein bereits bestehendes Theme bearbeitet (theme.json existiert
        // schon), bleibt dessen eigenes brand die Basis – nicht das gerade
        // aktive config.json. Sonst würde das Bearbeiten eines NICHT aktiven
        // Themes dessen brand mit dem Stand des GERADE aktiven Themes
        // überschreiben. Nur ein wirklich neues Theme startet bei brand vom
        // aktuell aktiven config (sinnvoller Ausgangspunkt).
        $existingThemeJson = is_file("{$themeDir}/theme.json") ? CMS::readJson("{$themeDir}/theme.json") : null;
        // Neues Theme (Kopie): Basis ist das Theme, von dem aus „Bearbeiten“ geklickt wurde.
        $source = trim((string) ($_POST['theme_source'] ?? ''));
        $sourceBrand = null;
        if ($existingThemeJson === null && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $source) === 1) {
            $sourceBrand = CMS::readJson($root . "/themes-and-plugins/themes/{$source}/theme.json")['brand'] ?? null;
        }
        $brand = $existingThemeJson['brand'] ?? $sourceBrand ?? $config['brand'] ?? [];
        $brand = array_replace($brand, $brandOverrides);

        $themeJson = [
            'name' => $slug,
            'label' => $label,
            'description' => trim((string) ($_POST['theme_description'] ?? '')),
            'brand' => $brand,
            'languages' => $languages,
            'layout' => $chosen,
        ];
        if ($palettes !== []) {
            $themeJson['palettes'] = $palettes;
        }
        if ($paletteRefs !== []) {
            $themeJson['palette_refs'] = array_values(array_unique($paletteRefs));
        }
        CMS::writeJson("{$themeDir}/theme.json", $themeJson);

        $config['theme'] = $slug;
        $config['languages'] = $languages;
        // Wie applyTheme: Das Theme definiert den Sprachsatz. Beim Speichern/
        // Aktivieren werden Basis-Sprachen, die es nicht mitbringt, ausgeblendet
        // und mitgebrachte wieder verfügbar gemacht – mitgebrachte toplevel,
        // nicht-mitgebrachte als languages_disabled.
        $config = $this->syncThemeLanguages($languages, $config);
        $config['layout']['header'] = $chosen['header'];
        $config['layout']['footer'] = $chosen['footer'];
        $config['layout']['stickybar'] = $chosen['stickybar'];
        $config['layout']['cookiebanner'] = $chosen['cookiebanner'];
        $config['layout']['topbar']['theme'] = $chosen['topbar'];
        CMS::writeJson($configPath, $config);

        $this->redirectToPanel('Theme „' . $label . '“ erstellt und aktiviert.', 'panel-theme');
    }

    /**
     * Löscht ein Theme (Ordner unter themes-and-plugins/themes/<name>/ komplett).
     * Das aktive Theme kann nicht gelöscht werden, um kein config.theme zu
     * hinterlassen, das ins Leere zeigt.
     */
    private function deleteTheme(): void
    {
        $name = trim((string) ($_POST['theme'] ?? ''));
        $config = CMS::readJson($this->cms->root() . '/data/config.json');
        if (($config['theme'] ?? '') === $name) {
            $this->redirectToPanel('Aktives Theme kann nicht gelöscht werden – erst ein anderes anwenden.', 'panel-theme');
        }

        foreach (glob($this->cms->root() . '/themes-and-plugins/themes/*/theme.json') ?: [] as $file) {
            $theme = CMS::readJson($file);
            if (($theme['name'] ?? '') === $name) {
                $this->rrmdir(dirname($file));
                $this->redirectToPanel('Theme „' . ($theme['label'] ?? $name) . '“ gelöscht.', 'panel-theme');
            }
        }
        $this->redirectToPanel('Theme nicht gefunden.', 'panel-theme');
    }

    private function assertCsrf(): void
    {
        $token = (string) ($_POST['csrf'] ?? '');
        if ($token !== '' && hash_equals($_SESSION['csrf'] ?? '', $token)) {
            return;
        }
        // Meist eine abgelaufene Sitzung (Admin lange offen, dann abgeschickt) –
        // statt nackter Fehlerseite zurück zum Login bzw. Dashboard mit Hinweis.
        // Die abgeschickte Aktion wird NICHT ausgeführt.
        if ($this->isLoggedIn()) {
            $this->redirect('Die Seite war nicht mehr aktuell – bitte die Aktion noch einmal ausführen.');
        }
        $_SESSION['login_notice'] = 'Sitzung abgelaufen – bitte erneut anmelden und die Aktion wiederholen.';
        header('Location: ?admin=1');
        exit;
    }

    private function redirect(string $message): never
    {
        $_SESSION['flash'] = $message;
        header('Location: ?admin=1');
        exit;
    }

    /**
     * Wie redirect(), landet aber gezielt auf einem bestimmten Panel statt auf
     * dem, das der Browser zufällig noch in der URL-Fragment stehen hat (ein
     * Redirect ohne eigenes Fragment lässt das alte im Browser meist unverändert
     * stehen) - für Aktionen wie "Update prüfen"/"Core aktualisieren", die
     * fachlich immer zu einem bestimmten Panel gehören, unabhängig davon, wo man
     * gerade zufällig war.
     */
    private function redirectToPanel(string $message, string $panel, string $cmd = '', string $after = ''): never
    {
        $_SESSION['flash'] = $message;
        $_SESSION['flash_command'] = $cmd;
        $_SESSION['flash_after'] = $after;
        header('Location: ?admin=1#' . $panel);
        exit;
    }
}
