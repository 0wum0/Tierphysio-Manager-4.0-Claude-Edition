<?php

declare(strict_types=1);

namespace Plugins\ThemeManager;

use App\Core\Controller;
use App\Core\Application;
use App\Core\View;

class ThemeController extends Controller
{
    private ThemeManager $themeManager;

    public function __construct()
    {
        parent::__construct();
        $this->themeManager = Application::getInstance()->getContainer()->get(ThemeManager::class);
    }

    /* ── Admin list ─────────────────────────────────────────── */

    public function index(array $params = []): void
    {
        $themes = $this->themeManager->all();
        $view   = Application::getInstance()->getContainer()->get(View::class);
        $view->render('@theme-manager/index.twig', [
            'page_title' => 'Theme Manager',
            'themes'     => $themes,
            'active'     => $this->themeManager->getActive(),
        ]);
    }

    /* ── Activate ───────────────────────────────────────────── */

    public function activate(array $params = []): void
    {
        $this->validateCsrf();

        $slug = $params['slug'] ?? '';
        if (empty($slug)) {
            $this->session->flash('error', 'Ungültiger Theme-Slug.');
            $this->redirect('/design');
            return;
        }

        /* Verify theme exists */
        $theme = $this->themeManager->get($slug);
        if (!$theme) {
            $this->session->flash('error', 'Theme nicht gefunden.');
            $this->redirect('/design');
            return;
        }

        $this->themeManager->setActive($slug);
        $this->session->flash('success', 'Theme "' . htmlspecialchars($theme['name']) . '" wurde aktiviert.');
        $this->redirect('/design');
    }

    /* ── Upload ZIP ─────────────────────────────────────────── */

    public function upload(array $params = []): void
    {
        $this->validateCsrf();

        if (empty($_FILES['theme_zip']) || $_FILES['theme_zip']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['theme_zip']['error'] ?? -1;
            $this->session->flash('error', 'Datei-Upload fehlgeschlagen (Code: ' . $errCode . ').');
            $this->redirect('/design');
            return;
        }

        $file = $_FILES['theme_zip'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $this->session->flash('error', 'Nur ZIP-Dateien sind erlaubt.');
            $this->redirect('/design');
            return;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            $this->session->flash('error', 'Die ZIP-Datei darf maximal 10 MB groß sein.');
            $this->redirect('/design');
            return;
        }

        try {
            $slug = $this->themeManager->installFromZip($file['tmp_name'], $file['name']);
            $theme = $this->themeManager->get($slug);
            $name  = $theme['name'] ?? $slug;
            $this->session->flash('success', 'Theme "' . htmlspecialchars($name) . '" wurde erfolgreich installiert.');
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Installation fehlgeschlagen: ' . $e->getMessage());
        }

        $this->redirect('/design');
    }

    /* ── Delete ─────────────────────────────────────────────── */

    public function delete(array $params = []): void
    {
        $this->validateCsrf();

        $slug = $params['slug'] ?? '';
        try {
            $theme = $this->themeManager->get($slug);
            $name  = $theme['name'] ?? $slug;
            $this->themeManager->delete($slug);
            $this->session->flash('success', 'Theme "' . htmlspecialchars($name) . '" wurde gelöscht.');
        } catch (\Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        $this->redirect('/design');
    }

    /* ── Serve theme asset (CSS, preview image, etc.) ────────── */

    public function serveAsset(array $params = []): void
    {
        $this->serveThemeFile(
            $params['slug'] ?? '',
            $params['file'] ?? ''
        );
    }

    public function serveSubAsset(array $params = []): void
    {
        $this->serveThemeFile(
            $params['slug'] ?? '',
            'assets/' . ($params['file'] ?? '')
        );
    }

    private function serveThemeFile(string $slug, string $file): void
    {
        /* Sanitize slug */
        $slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower($slug));

        /* Prevent directory traversal */
        $file = str_replace(['..', "\0"], '', $file);
        $file = ltrim($file, '/');

        $allowed = ['theme.css', 'preview.png', 'preview.jpg'];
        $isSubAsset = str_starts_with($file, 'assets/');
        if (!in_array($file, $allowed, true) && !$isSubAsset) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $path = STORAGE_PATH . '/themes/' . $slug . '/' . $file;
        if (!file_exists($path)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'css'  => 'text/css',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'js'   => 'application/javascript',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=3600');
        readfile($path);
    }
}
