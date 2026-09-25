<?php

declare(strict_types=1);

namespace Core;

final class Admin
{
    public function __construct(private readonly CMS $cms)
    {
    }

    public function handle(): void
    {
        $action = (string) ($_GET['do'] ?? $_POST['do'] ?? '');

        // Läuft VOR jeder Session-/Login-Prüfung: ein CI-Job (siehe
        // dev/templates/deploy-staging.yml) hat keine Session, authentifiziert
        // sich stattdessen per Bearer-Token gegen BACKUP_TOKEN aus .env.
        if ($action === 'backup') {
            $this->runBackup();
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
                'image-upload' => $this->uploadImage(),
                'image-delete' => $this->deleteImage(),
                'image-delete-many' => $this->deleteImagesMany(),
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
     * Aktionen, die einen Account mit Admin-Rolle voraussetzen (Benutzer verwalten, Core-Update).
     * Bildverwaltung (image-upload/-delete/-import) gehört zu Stammdaten und bleibt für alle
     * eingeloggten Accounts nutzbar – nur Beschriftungen/Benutzer/Update sind Admin-only.
     */
    private const ADMIN_ONLY_ACTIONS = [
        'check-update', 'update', 'check-components-update', 'components-update',
        'user-add', 'user-remove', 'user-setpw',
        'apply-theme', 'build-theme', 'delete-theme', 'toggle-topbar',
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

    private function handleLogin(): void
    {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->assertCsrf();
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $user = $this->findUser($username);
            $hash = (string) ($user['password_hash'] ?? '');
            if ($user !== null && $hash !== '' && password_verify($password, $hash)) {
                $_SESSION['admin'] = ['username' => $username, 'is_admin' => !empty($user['is_admin'])];
                header('Location: ?admin=1');
                exit;
            }
            $error = 'Benutzername oder Passwort stimmt nicht.';
        }

        $content = CMS::readJson($this->cms->root() . '/data/content.json');

        echo $this->cms->twig()->render('admin-login.twig', [
            'error' => $error,
            'site_name' => $this->displayString($content['site']['title'] ?? '', 'CMS'),
        ]);
    }

    private function findUser(string $username): ?array
    {
        if ($username === '') {
            return null;
        }
        foreach ($this->usersList() as $user) {
            if (strcasecmp((string) ($user['username'] ?? ''), $username) === 0) {
                return $user;
            }
        }
        return null;
    }

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
            'csrf' => $_SESSION['csrf'] ?? '',
            'users' => $this->usersList(),
            'current_user' => $this->currentUsername(),
            'current_is_admin' => $this->currentIsAdmin(),
            'uploads' => $this->listUploads(),
            'active_languages' => CMS::activeLanguages($this->cms->config()),
            'available_languages' => $this->availableLanguages(),
            'dev_imports' => $this->scanDevImports(),
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

    /** JSON an den Browser (KI-Endpoints), REST-mäßig ohne Redirect wie die übrigen Formular-Aktionen. */
    private function respondJson(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function saveContent(): void
    {
        $path = $this->cms->root() . '/data/content.json';
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
        $this->redirect($message);
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
        echo json_encode(['ok' => true]);
        exit;
    }

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
     * Sichert nur data/ (Content, Config, Bearbeitungsverlauf) und uploads/
     * (Bilder) - der Code selbst kommt aus Git und braucht kein Backup.
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
        $backupDir = $root . '/cache/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'backup directory could not be created']);
            exit;
        }

        $file = $backupDir . '/backup-' . date('Ymd-His') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'zip could not be created']);
            exit;
        }
        if (is_dir($root . '/data')) {
            $this->addDirToZip($zip, $root . '/data', 'data');
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
        copy($root . '/data/content.json', $dest . '/data/content.json');
        // Einmalige Kopie für CMS::ensureSeeded(): content.json selbst ist im
        // Git-Repo-Export (siehe setUpGitWorkflow()) vom Deploy-Workflow
        // ausgeschlossen, damit spätere Live-Bearbeitungen nicht überschrieben
        // werden - beim allerersten Deploy käme dadurch aber nie ein Inhalt
        // an. content.seed.json trägt denselben Ursprungsstand, wird vom
        // Workflow NICHT ausgeschlossen (anderer Dateiname).
        copy($root . '/data/content.json', $dest . '/data/content.seed.json');
        $config = $this->cms->config();
        unset($config['dev_import']);
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
            // Eigene Kopie der Region im Theme-Ordner hat Vorrang (siehe
            // CMS::renderRegion()) - dann muss der gemeinsame Pool dafür nicht mit.
            if ($theme !== '' && is_dir($root . "/themes-and-plugins/themes/{$theme}/{$region}")) {
                continue;
            }
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
                : ' Git-Repo mit main/develop/staging angelegt (auf develop), FTP-Deploy-Workflow liegt unter .github/workflows/deploy-staging.yml bereit - im Ziel-Repo noch die GitHub-Secrets FTP_SERVER/FTP_USERNAME/FTP_PASSWORD/FTP_TARGET_DIR/BACKUP_URL/BACKUP_TOKEN setzen.';
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
            if (!preg_match('/^([a-z0-9][a-z0-9-]*)\s+(\d+)\s+(.+)$/', $line, $m)) {
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

        copy($root . '/data/content.json', $dest . '/data/content.json');
        copy($root . '/data/content.json', $dest . '/data/content.seed.json');
        $config = $this->cms->config();
        unset($config['dev_import']);
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
            if ($theme !== '' && is_dir($root . "/themes-and-plugins/themes/{$theme}/{$region}")) {
                continue;
            }
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
        unset($cleanConfig['dev_import']);
        $zip->addFromString('data/config.json', (string) json_encode($cleanConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (is_dir($root . '/uploads')) {
            $this->addDirToZipSkipping($zip, $root . '/uploads', 'uploads', ['.optim']);
        }

        $theme = (string) ($cleanConfig['theme'] ?? '');
        if ($theme !== '' && is_dir($root . "/themes-and-plugins/themes/{$theme}")) {
            $this->addDirToZip($zip, $root . "/themes-and-plugins/themes/{$theme}", 'themes-and-plugins/themes/' . $theme);
        }
        foreach (['header', 'footer', 'topbar', 'stickybar', 'cookiebanner'] as $region) {
            if ($theme !== '' && is_dir($root . "/themes-and-plugins/themes/{$theme}/{$region}")) {
                continue;
            }
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

        // Ein Export nimmt bei einem Theme mit eigener Region-Kopie
        // (themes-and-plugins/themes/<theme>/<region>/) bewusst NICHT den
        // kompletten gemeinsamen Pool mit (siehe exportProjectInstance()) -
        // auf so einer schlanken Instanz wäre der Picker oben sonst leer,
        // und die aktuell verwendete Variante würde fälschlich als
        // "— Keine —" erscheinen, obwohl CMS::renderRegion() sie (die
        // Theme-eigene Kopie hat ohnehin Vorrang) tatsächlich rendert.
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
        if (!move_uploaded_file($tmp, $target)) {
            return $fallback;
        }
        Image::generateVariants($target);

        return 'uploads/' . basename($target);
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

    private function addUser(): void
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $this->redirectToPanel('Benutzername und Passwort angeben.', 'panel-users');
        }
        if (strlen($password) < 8) {
            $this->redirectToPanel('Passwort muss mindestens 8 Zeichen haben.', 'panel-users');
        }

        $path = $this->cms->root() . '/data/config.json';
        $config = CMS::readJson($path);
        $users = is_array($config['admin']['users'] ?? null) ? $config['admin']['users'] : [];
        foreach ($users as $user) {
            if (strcasecmp((string) ($user['username'] ?? ''), $username) === 0) {
                $this->redirectToPanel('Benutzername existiert bereits.', 'panel-users');
            }
        }

        $users[] = [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin' => ($_POST['is_admin'] ?? '0') === '1',
        ];
        $config['admin']['users'] = array_values($users);
        CMS::writeJson($path, $config);
        $this->redirectToPanel('Benutzer angelegt.', 'panel-users');
    }

    private function removeUser(): void
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        if (strcasecmp($username, $this->currentUsername()) === 0) {
            $this->redirectToPanel('Du kannst dich nicht selbst entfernen.', 'panel-users');
        }

        $path = $this->cms->root() . '/data/config.json';
        $config = CMS::readJson($path);
        $users = is_array($config['admin']['users'] ?? null) ? $config['admin']['users'] : [];

        $exists = false;
        foreach ($users as $user) {
            if (strcasecmp((string) ($user['username'] ?? ''), $username) === 0) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $this->redirectToPanel('Benutzer nicht gefunden.', 'panel-users');
        }
        if (count($users) <= 1) {
            $this->redirectToPanel('Es muss mindestens ein Benutzer bestehen bleiben.', 'panel-users');
        }

        $remainingAdmins = array_filter(
            $users,
            static fn ($user) => !empty($user['is_admin']) && strcasecmp((string) ($user['username'] ?? ''), $username) !== 0
        );
        $wasAdmin = (bool) array_filter($users, static fn ($user) => strcasecmp((string) ($user['username'] ?? ''), $username) === 0 && !empty($user['is_admin']));
        if ($wasAdmin && $remainingAdmins === []) {
            $this->redirectToPanel('Es muss mindestens ein Admin-Benutzer bestehen bleiben.', 'panel-users');
        }

        $config['admin']['users'] = array_values(array_filter(
            $users,
            static fn ($user) => strcasecmp((string) ($user['username'] ?? ''), $username) !== 0
        ));
        CMS::writeJson($path, $config);
        $this->redirectToPanel('Benutzer entfernt.', 'panel-users');
    }

    private function setUserPassword(): void
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            $this->redirectToPanel('Passwort muss mindestens 8 Zeichen haben.', 'panel-users');
        }

        $path = $this->cms->root() . '/data/config.json';
        $config = CMS::readJson($path);
        $users = is_array($config['admin']['users'] ?? null) ? $config['admin']['users'] : [];
        $found = false;
        foreach ($users as &$user) {
            if (strcasecmp((string) ($user['username'] ?? ''), $username) === 0) {
                $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $found = true;
                break;
            }
        }
        unset($user);
        if (!$found) {
            $this->redirectToPanel('Benutzer nicht gefunden.', 'panel-users');
        }

        $config['admin']['users'] = $users;
        CMS::writeJson($path, $config);
        $this->redirectToPanel('Passwort geändert.', 'panel-users');
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
        if (isset($theme['languages'])) {
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
        $this->redirectToPanel('Theme „' . ($theme['label'] ?? $name) . '“ angewendet.', 'panel-theme');
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

        $root = $this->cms->root();
        $themeDir = $root . "/themes-and-plugins/themes/{$slug}";
        if (!is_dir($themeDir) && !mkdir($themeDir, 0775, true) && !is_dir($themeDir)) {
            $this->redirectToPanel('Theme-Ordner konnte nicht angelegt werden.', 'panel-theme');
        }

        foreach ($chosen as $region => $variant) {
            $dstDir = "{$themeDir}/{$region}";
            if ($variant === '') {
                // "— Keine —" gewählt: eine evtl. vorhandene eigene Kopie aus
                // einem früheren Speichern muss weg, sonst würde renderRegion()
                // sie weiter bevorzugt rendern und "Keine" hätte keine Wirkung.
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
        $brand = $existingThemeJson['brand'] ?? $config['brand'] ?? [];

        $themeJson = [
            'name' => $slug,
            'label' => $label,
            'description' => trim((string) ($_POST['theme_description'] ?? '')),
            'brand' => $brand,
            'languages' => $languages,
            'layout' => $chosen,
        ];
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
        Image::generateVariants($target);

        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'path' => 'uploads/' . $name, 'name' => $name]);
            exit;
        }

        $this->redirectToPanel('Bild hochgeladen.', 'panel-images');
    }

    private function failUpload(bool $ajax, string $message): void
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
        Image::generateVariants($dir . '/' . $name);

        $rel = 'uploads/' . $name;
        $mapping[$url] = $rel;
        return $rel;
    }

    /** @return list<array{username:string,password_hash:string,is_admin?:bool}> */
    private function usersList(): array
    {
        $config = CMS::readJson($this->cms->root() . '/data/config.json');
        $users = $config['admin']['users'] ?? [];
        return is_array($users) ? array_values($users) : [];
    }

    private function currentUsername(): string
    {
        return (string) ($_SESSION['admin']['username'] ?? '');
    }

    private function currentIsAdmin(): bool
    {
        return !empty($_SESSION['admin']['is_admin']);
    }

    private function isLoggedIn(): bool
    {
        return !empty($_SESSION['admin']['username']);
    }

    private function assertCsrf(): void
    {
        $token = (string) ($_POST['csrf'] ?? '');
        if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(400);
            echo 'Ungültige Sitzung.';
            exit;
        }
    }

    private function redirect(string $message): void
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
    private function redirectToPanel(string $message, string $panel, string $cmd = '', string $after = ''): void
    {
        $_SESSION['flash'] = $message;
        $_SESSION['flash_command'] = $cmd;
        $_SESSION['flash_after'] = $after;
        header('Location: ?admin=1#' . $panel);
        exit;
    }

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
        $config = is_file($configSrc) ? CMS::readJson($configSrc) : CMS::readJson($configPath);
        $config['dev_import'] = $slug;
        // Die Update-Manifest-URLs sind Deploy-Infrastruktur des Generators und
        // kein Projekt-Inhalt: beim Projekt-Wechsel nie überschreiben, sonst
        // sind die Update-Buttons in dieser (und jeder danach exportierten)
        // Instanz ohne Grund ausgegraut (bl01-Fall).
        $config['update'] = CMS::readJson($configPath)['update'] ?? [];
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
