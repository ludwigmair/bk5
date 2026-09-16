<?php

declare(strict_types=1);

session_start();

$root = __DIR__;
require $root . '/vendor/autoload.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$route = $_GET['admin'] ?? $_GET['action'] ?? '';

if ($route === '1' || $route === 'admin' || isset($_GET['admin'])) {
    // Admin bekommt den Content unlokalisiert (boot() ohne $locale) – sonst
    // wären mehrsprachige Felder dort nur noch als aufgelöster String
    // sichtbar, statt als bearbeitbare Sprach-Map.
    (new Core\Admin(Core\CMS::boot($root)))->handle();
    exit;
}

$rawPath = trim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
$detected = Core\CMS::detectLocale($root, $rawPath);
$cms = Core\CMS::boot($root, $detected['locale']);
$path = $detected['path'];

if (isset($_GET['action']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitHook = $root . '/themes-and-plugins/components/' . (string) $_GET['action'] . '/submit.php';
    if (is_file($submitHook)) {
        header('Content-Type: application/json; charset=utf-8');

        $token = (string) ($_POST['csrf'] ?? '');
        if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            echo json_encode(['ok' => false, 'message' => 'Sitzung ungültig. Bitte Seite neu laden.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $handle = require $submitHook;
        echo json_encode($handle($_POST, $cms), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (in_array($path, ['impressum', 'datenschutz'], true)) {
    $legalHtml = $cms->renderLegal($path);
    if ($legalHtml !== null) {
        echo $legalHtml;
        exit;
    }
}

echo $cms->render();
