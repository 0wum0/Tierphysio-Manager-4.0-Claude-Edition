<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Repositories\UserPreferencesRepository;

class UiSettingsController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly UserPreferencesRepository $prefsRepo
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function save(array $params = []): void
    {
        header('Content-Type: application/json');

        $userId = (int)$this->session->get('user_id');
        if (!$userId) {
            echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
            return;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            echo json_encode(['ok' => false, 'error' => 'invalid json']);
            return;
        }

        $this->prefsRepo->set($userId, 'ui_layout_settings', json_encode($data));
        echo json_encode(['ok' => true]);
    }

    public function load(array $params = []): void
    {
        header('Content-Type: application/json');

        $userId = (int)$this->session->get('user_id');
        if (!$userId) {
            echo json_encode(null);
            return;
        }

        $raw = $this->prefsRepo->get($userId, 'ui_layout_settings');
        if ($raw) {
            echo $raw;
        } else {
            echo json_encode(null);
        }
    }
}
