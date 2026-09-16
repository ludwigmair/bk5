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
                'image-upload' => $this->uploadImage(),
                'image-delete' => $this->deleteImage(),
                'image-import' => $this->importImages(),
                'reorder-sections' => $this->reorderSections(),
                'switch-project' => $this->switchProject(),
                'restore-import' => $this->restoreImportBaseline(),
                'restore-history' => $this->restoreHistory(),
                'build-update-package' => $this->buildUpdatePackage(),
                'delete-update-package' => $this->deleteUpdatePackage(),
                'build-components-update-package' => $this->buildComponentsUpdatePackage(),
                'delete-components-update-package' => $this->deleteComponentsUpdatePackage(),
                'export-instance' => $this->exportProjectInstance(),
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
        'apply-theme', 'build-theme', 'delete-theme',
        'add', 'delete', 'reorder-sections',
        'switch-project', 'restore-import', 'restore-history',
        'build-update-package', 'download-update-package', 'delete-update-package',
        'build-components-update-package', 'download-components-update-package', 'delete-components-update-package',
        'export-instance',
    ];

    /** Sprachen, die zur Auswahl stehen – "de" ist immer Pflicht/Default, siehe normalizeLanguages(). */
    private const AVAILABLE_LANGUAGES = [
        'de' => 'Deutsch', 'en' => 'Englisch', 'fr' => 'Französisch',
        'it' => 'Italienisch', 'es' => 'Spanisch', 'nl' => 'Niederländisch',
    ];

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
            'csrf' => $_SESSION['csrf'] ?? '',
            'users' => $this->usersList(),
            'current_user' => $this->currentUsername(),
            'current_is_admin' => $this->currentIsAdmin(),
            'uploads' => $this->listUploads(),
            'active_languages' => CMS::activeLanguages($this->cms->config()),
            'available_languages' => self::AVAILABLE_LANGUAGES,
            'dev_imports' => $this->scanDevImports(),
            'active_dev_import' => $this->cms->config()['dev_import'] ?? null,
            'content_history' => $this->listHistory(),
            'update_packages' => $this->listUpdatePackages(),
            'core_modified' => $this->coreModifiedSincePackage(),
            'update_info' => $updateInfo,
            'components_version' => CMS::readJson($this->componentsDir() . '/version.json')['version'] ?? '1.0.0',
            'components_update_packages' => $this->listComponentsUpdatePackages(),
            'components_modified' => $this->componentsModifiedSincePackage(),
            'components_update_info' => $componentsUpdateInfo,
            'has_dev_tools' => $hasDevTools,
        ], $extra));
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
            if (!in_array($background, ['auto', 'white', 'tint', 'accent'], true)) {
                $background = 'auto';
            }
            $sections[] = [
                'id' => $idMap[$id] ?? $id,
                'type' => $type,
                'sort' => (int) ($row['sort'] ?? 0),
                'label' => trim((string) ($row['label'] ?? '')),
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
            foreach (['impressum', 'datenschutz'] as $key) {
                if (!isset($_POST['legal'][$key]['body'])) {
                    continue;
                }
                $content['legal'][$key]['body'] = $this->translatableValue($_POST['legal'][$key]['body'], $content['legal'][$key]['body'] ?? '');
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

        $this->redirectToPanel('Update-Paket ' . $version . ' erstellt (core/version.json wurde mit hochgezählt). '
            . 'Noch nicht live für andere Instanzen – dafür zusätzlich "php dev/build-release.php ' . $version . ' --repo <releases-repo>" ausführen (siehe docs/UPDATE-CORE.md).', 'panel-update-package');
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

        $this->redirectToPanel('Components-Update-Paket ' . $version . ' erstellt (components/version.json wurde mit hochgezählt). '
            . 'Noch nicht live für andere Instanzen – dafür zusätzlich "php dev/build-release.php ' . $version . ' --repo <releases-repo> --target components" ausführen (siehe docs/UPDATE-COMPONENTS.md).', 'panel-components-update');
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
        $config = $this->cms->config();
        unset($config['dev_import']);
        CMS::writeJson($dest . '/data/config.json', $config);

        if (is_dir($root . '/uploads')) {
            $this->copyDir($root . '/uploads', $dest . '/uploads');
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
     * Optional beim Export: eigenes Git-Repo mit main/develop/staging-Branches
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
        $layout = $this->cms->config()['layout'] ?? [];
        $defaults = ['header' => 'standard-nav', 'footer' => 'simple-footer', 'topbar' => 'standard', 'stickybar' => 'icon-rail', 'cookiebanner' => 'corner'];
        $variant = $region === 'topbar'
            ? (string) ($layout['topbar']['theme'] ?? $defaults['topbar'])
            : (string) ($layout[$region] ?? $defaults[$region] ?? '');

        $path = $this->cms->root() . "/themes-and-plugins/{$region}s/{$variant}/schema.json";
        return is_file($path) ? CMS::readJson($path) : [];
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
            $config['languages'] = $this->normalizeLanguages($theme['languages']);
        }
        $config['theme'] = $name;

        CMS::writeJson($path, $config);
        $this->redirectToPanel('Theme „' . ($theme['label'] ?? $name) . '“ angewendet.', 'panel-theme');
    }

    /**
     * "de" ist immer Pflicht und steht immer an erster Stelle (= Default-/
     * Fallback-Sprache, siehe CMS::detectLocale()/localizeContent()). Reduziert
     * sich die Auswahl auf nur "de", verschwindet der Sprach-Umschalter im
     * Admin wieder und jedes Feld zeigt ein einzelnes Eingabefeld statt der
     * Sprach-Tabs – nichts geht dabei verloren, die Sprach-Maps in
     * content.json bleiben einfach ungenutzt liegen.
     */
    private function normalizeLanguages(mixed $selected): array
    {
        $selected = is_array($selected) ? array_map('strval', $selected) : [];
        $selected = array_values(array_intersect($selected, array_keys(self::AVAILABLE_LANGUAGES)));

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
            unlink($file);
        }
        $this->redirectToPanel('Bild gelöscht.', 'panel-images');
    }

    /**
     * Bilder in web/uploads/ mit Verwendungs-Status (welche Sections referenzieren sie).
     * @return list<array{name:string, used:bool, usedIn:list<string>}>
     */
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

    /** @param array<string, mixed> $fields @param array<string, mixed> $data @param array<string, string> $mapping */
    private function importImagesInData(array $fields, array &$data, array &$mapping): void
    {
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
    private function redirectToPanel(string $message, string $panel): void
    {
        $_SESSION['flash'] = $message;
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
            if (isset($source['legal'][$key]['body'])) {
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
