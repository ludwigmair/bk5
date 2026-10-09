<?php

declare(strict_types=1);

namespace Core\AdminParts;

use Core\CMS;

/**
 * Teil von Core\Admin (per `use` eingebunden, läuft im Kontext von Admin –
 * $this->cms, Konstanten und alle anderen Admin-Methoden stehen zur Verfügung).
 *
 * Login (inkl. Sperre nach Fehlversuchen) und Benutzerverwaltung.
 */
trait UserActions
{
    private function handleLogin(): void
    {
        $error = (string) ($_SESSION['login_notice'] ?? '');
        unset($_SESSION['login_notice']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->assertCsrf();
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $wait = $this->loginLockSeconds();
            if ($wait > 0) {
                $error = 'Zu viele Fehlversuche. Bitte in ' . (int) ceil($wait / 60) . ' Minute(n) erneut versuchen.';
            } else {
                $user = $this->findUser($username);
                $hash = (string) ($user['password_hash'] ?? '');
                if ($user !== null && $hash !== '' && password_verify($password, $hash)) {
                    $this->recordLoginAttempt(true);
                    // Neue Session-ID nach dem Login: eine vorher untergeschobene ID
                    // (Session-Fixation) ist damit wertlos.
                    session_regenerate_id(true);
                    $_SESSION['admin'] = ['username' => $username, 'is_admin' => !empty($user['is_admin'])];
                    header('Location: ?admin=1');
                    exit;
                }
                $this->recordLoginAttempt(false);
                $error = 'Benutzername oder Passwort stimmt nicht.';
            }
        }

        $content = CMS::readJson($this->cms->root() . '/data/content.json');

        echo $this->cms->twig()->render('admin-login.twig', [
            'error' => $error,
            'site_name' => $this->displayString($content['site']['title'] ?? '', 'CMS'),
        ]);
    }

    /** Fehlversuche je IP (gehasht) in cache/login-attempts.json – Datei statt Session, die ein Angreifer einfach verwirft. */
    private function loginAttemptsPath(): string
    {
        return $this->cms->root() . '/cache/login-attempts.json';
    }

    private function loginClientKey(): string
    {
        return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /** Restliche Sperrzeit in Sekunden für die aktuelle IP, 0 = darf versuchen. */
    private function loginLockSeconds(): int
    {
        $entry = CMS::readJson($this->loginAttemptsPath())[$this->loginClientKey()] ?? null;
        if (!is_array($entry) || (int) ($entry['fails'] ?? 0) < self::LOGIN_MAX_FAILS) {
            return 0;
        }

        return max(0, (int) ($entry['last'] ?? 0) + self::LOGIN_LOCK_SECONDS - time());
    }

    private function recordLoginAttempt(bool $success): void
    {
        $path = $this->loginAttemptsPath();
        $all = CMS::readJson($path);
        $now = time();
        // Abgelaufene Einträge anderer IPs gleich mit wegräumen, damit die Datei klein bleibt.
        $all = array_filter($all, static fn ($e) => is_array($e) && (int) ($e['last'] ?? 0) + self::LOGIN_LOCK_SECONDS > $now);
        $key = $this->loginClientKey();
        if ($success) {
            unset($all[$key]);
        } else {
            $fails = (int) ($all[$key]['fails'] ?? 0) + 1;
            $all[$key] = ['fails' => $fails, 'last' => $now];
        }
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        CMS::writeJson($path, $all);
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
}
