<?php

declare(strict_types=1);

namespace Saas\Controllers;

use Saas\Core\Controller;
use Saas\Core\View;
use Saas\Core\Session;
use Saas\Services\MigrationRunner;

class UpdaterController extends Controller
{
    public function __construct(
        View                   $view,
        Session                $session,
        private MigrationRunner $migrationRunner
    ) {
        parent::__construct($view, $session);
    }

    public function index(array $params = []): void
    {
        $this->requireAuth();

        $this->migrationRunner->ensureTrackingTable();

        $status  = $this->migrationRunner->getStatus();
        $pending = $this->migrationRunner->getPending();

        $this->render('admin/updater/index.twig', [
            'page_title'    => 'System-Updater',
            'active_nav'    => 'updater',
            'migrations'    => $status,
            'pending_count' => count($pending),
            'results'       => $this->session->getFlash('migration_results'),
        ]);
    }

    public function run(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $this->migrationRunner->ensureTrackingTable();

        $results = $this->migrationRunner->runPending();

        $this->session->flash('migration_results', $results);
        $this->redirect('/admin/updater');
    }

    public function reset(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $name = trim($this->post('migration', ''));
        if ($name) {
            $this->migrationRunner->ensureTrackingTable();
            $this->migrationRunner->resetMigration($name);
            $this->session->flash('migration_results', [[
                'name'    => $name,
                'status'  => 'success',
                'message' => 'Migration zurückgesetzt — wird beim nächsten Run erneut ausgeführt.',
            ]]);
        }
        $this->redirect('/admin/updater');
    }
}
