<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Core\PluginManager;
use App\Services\SettingsService;
use App\Services\MigrationService;
use App\Repositories\UserRepository;
use App\Repositories\TreatmentTypeRepository;

class SettingsController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly SettingsService $settingsService,
        private readonly PluginManager $pluginManager,
        private readonly MigrationService $migrationService,
        private readonly UserRepository $userRepository,
        private readonly TreatmentTypeRepository $treatmentTypeRepository
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function index(array $params = []): void
    {
        $settings        = $this->settingsService->all();
        $users           = $this->userRepository->findAll();
        $plugins         = $this->pluginManager->getAllAvailablePlugins();
        $currentVersion  = $this->migrationService->getCurrentVersion();
        $latestVersion   = $this->migrationService->getLatestVersion();
        $pendingMigrations = $this->migrationService->getPendingMigrations();

        $treatmentTypes = [];
        try {
            $treatmentTypes = $this->treatmentTypeRepository->findAll();
        } catch (\Throwable) {}

        $this->render('settings/index.twig', [
            'page_title'       => $this->translator->trans('nav.settings'),
            'settings'         => $settings,
            'users'            => $users,
            'plugins'          => $plugins,
            'current_version'  => $currentVersion,
            'latest_version'   => $latestVersion,
            'pending'          => $pendingMigrations,
            'up_to_date'       => empty($pendingMigrations),
            'php_version'      => PHP_VERSION,
            'app_env'          => $this->config->get('app.env', 'production'),
            'active_tab'       => $params['tab'] ?? ($_GET['tab'] ?? 'firma'),
            'treatment_types'  => $treatmentTypes,
            'email_tpl_defaults' => [
                'invoice_subject'  => 'Ihre Rechnung {{invoice_number}}',
                'invoice_body'     => "Sehr geehrte/r {{owner_name}},\n\nanbei erhalten Sie Ihre Rechnung {{invoice_number}} vom {{issue_date}}.\n\nGesamtbetrag: {{total_gross}}\nBitte überweisen Sie den Betrag bis zum {{due_date}}.\n\nMit freundlichen Grüßen\n{{company_name}}",
                'receipt_subject'  => 'Ihre Quittung {{invoice_number}}',
                'receipt_body'     => "Sehr geehrte/r {{owner_name}},\n\nvielen Dank für Ihre Zahlung. Anbei erhalten Sie Ihre Quittung für Rechnung {{invoice_number}} vom {{issue_date}}.\n\nBezahlter Betrag: {{total_gross}}\n\nMit freundlichen Grüßen\n{{company_name}}",
                'reminder_subject' => 'Terminerinnerung: {{appointment_title}} am {{appointment_date}}',
                'reminder_body'    => "Hallo {{owner_name}},\n\nwir möchten Sie an Ihren bevorstehenden Termin erinnern:\n\n\u{1F4C5} {{appointment_title}}\nDatum: {{appointment_date}}\nUhrzeit: {{appointment_time}}\n{{appointment_patient}}\n\nFalls Sie den Termin absagen oder verschieben möchten, kontaktieren Sie uns bitte rechtzeitig.\n\nMit freundlichen Grüßen\n{{company_name}}",
                'invite_subject'   => 'Ihre Einladung zur Anmeldung \u2014 {{company_name}}',
                'invite_body'      => "Sie wurden eingeladen!\n\n{{from_name}} lädt Sie ein, Ihr Tier und sich als Besitzer direkt in unserem System zu registrieren.\n\n{{note}}\n\nJetzt registrieren:\n{{invite_url}}\n\nDieser Link ist 7 Tage gültig.\n\nMit freundlichen Grüßen\n{{company_name}}",
            ],
        ]);
    }

    public function update(array $params = []): void
    {
        $this->validateCsrf();

        $allowed = [
            'company_name', 'company_street', 'company_zip', 'company_city',
            'company_phone', 'company_email', 'company_website',
            'bank_name', 'bank_iban', 'bank_bic',
            'payment_terms', 'invoice_prefix', 'invoice_start_number',
            'tax_number', 'vat_number', 'default_tax_rate', 'kleinunternehmer',
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
            'smtp_encryption', 'mail_from_name', 'mail_from_address',
            'default_language', 'default_theme',
            'pdf_primary_color', 'pdf_accent_color', 'pdf_row_color',
            'pdf_color_company_name', 'pdf_color_company_info', 'pdf_color_recipient',
            'pdf_color_table_header_bg', 'pdf_color_table_header_text',
            'pdf_color_table_text', 'pdf_color_line', 'pdf_color_total_label',
            'pdf_color_total_gross', 'pdf_color_footer',
            'pdf_font', 'pdf_font_size', 'pdf_layout',
            'pdf_header_style', 'pdf_logo_position', 'pdf_logo_width', 'pdf_margin',
            'pdf_show_logo', 'pdf_show_patient', 'pdf_show_chip',
            'pdf_show_page_numbers', 'pdf_show_iban', 'pdf_show_tax_number', 'pdf_show_website',
            'pdf_zebra_rows', 'pdf_watermark',
            'pdf_footer_text', 'pdf_intro_text', 'pdf_closing_text',
            'calendar_cron_secret',
            'mail_imap_host', 'mail_imap_port', 'mail_imap_encrypt', 'mail_imap_user',
            'email_invoice_subject',  'email_invoice_body',
            'email_receipt_subject',  'email_receipt_body',
            'email_reminder_subject', 'email_reminder_body',
            'email_invite_subject',   'email_invite_body',
        ];

        $data = [];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                $data[$key] = $this->sanitize($_POST[$key]);
            }
        }

        if (empty($data)) {
            $this->session->flash('error', 'DEBUG: Keine Daten empfangen. POST-Keys: ' . implode(', ', array_keys($_POST)));
            $this->redirect('/einstellungen');
            return;
        }

        foreach ($data as $key => $value) {
            $this->settingsService->set($key, $value);
        }

        $this->session->flash('success', $this->translator->trans('settings.saved'));
        $this->redirect('/einstellungen');
    }

    public function uploadLogo(array $params = []): void
    {
        $this->validateCsrf();

        $destination = STORAGE_PATH . '/uploads';
        $filename    = $this->uploadFile('logo', $destination, ['image/jpeg', 'image/png', 'image/svg+xml', 'image/gif']);

        if ($filename === false) {
            $this->session->flash('error', $this->translator->trans('settings.logo_upload_failed'));
            $this->redirect('/einstellungen');
            return;
        }

        $this->settingsService->set('company_logo', $filename);
        $this->session->flash('success', $this->translator->trans('settings.logo_updated'));
        $this->redirect('/einstellungen');
    }

    public function uploadPdfRechnungBild(array $params = []): void
    {
        $this->validateCsrf();
        $dest     = ROOT_PATH . '/public/assets/img';
        $filename = $this->uploadFile('pdf_rechnung_bild', $dest, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        if ($filename === false) {
            $this->session->flash('error', 'Bild-Upload fehlgeschlagen.');
            $this->redirect('/einstellungen?tab=pdf');
            return;
        }
        // Rename to fixed filename so PdfService always finds it
        $ext = pathinfo($dest . '/' . $filename, PATHINFO_EXTENSION);
        rename($dest . '/' . $filename, $dest . '/rechnung-script.' . $ext);
        $this->settingsService->set('pdf_rechnung_bild', 'rechnung-script.' . $ext);
        $this->session->flash('success', '"Rechnung"-Bild aktualisiert.');
        $this->redirect('/einstellungen?tab=pdf');
    }

    public function uploadPdfVielenDankBild(array $params = []): void
    {
        $this->validateCsrf();
        $dest     = ROOT_PATH . '/public/assets/img';
        $filename = $this->uploadFile('pdf_vielen_dank_bild', $dest, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        if ($filename === false) {
            $this->session->flash('error', 'Bild-Upload fehlgeschlagen.');
            $this->redirect('/einstellungen?tab=pdf');
            return;
        }
        $ext = pathinfo($dest . '/' . $filename, PATHINFO_EXTENSION);
        rename($dest . '/' . $filename, $dest . '/vielen-dank-script.' . $ext);
        $this->settingsService->set('pdf_vielen_dank_bild', 'vielen-dank-script.' . $ext);
        $this->session->flash('success', '"Vielen Dank!"-Bild aktualisiert.');
        $this->redirect('/einstellungen?tab=pdf');
    }

    public function uploadPdfQuittungBild(array $params = []): void
    {
        $this->validateCsrf();
        $dest     = ROOT_PATH . '/public/assets/img';
        $filename = $this->uploadFile('pdf_quittung_bild', $dest, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        if ($filename === false) {
            $this->session->flash('error', 'Bild-Upload fehlgeschlagen.');
            $this->redirect('/einstellungen?tab=pdf');
            return;
        }
        $ext = pathinfo($dest . '/' . $filename, PATHINFO_EXTENSION);
        rename($dest . '/' . $filename, $dest . '/quittung-script.' . $ext);
        $this->settingsService->set('pdf_quittung_bild', 'quittung-script.' . $ext);
        $this->session->flash('success', '"Quittung"-Bild aktualisiert.');
        $this->redirect('/einstellungen?tab=pdf');
    }

    public function uploadPdfBarzahlungBild(array $params = []): void
    {
        $this->validateCsrf();
        $dest     = ROOT_PATH . '/public/assets/img';
        $filename = $this->uploadFile('pdf_barzahlung_bild', $dest, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        if ($filename === false) {
            $this->session->flash('error', 'Bild-Upload fehlgeschlagen.');
            $this->redirect('/einstellungen?tab=pdf');
            return;
        }
        $ext = pathinfo($dest . '/' . $filename, PATHINFO_EXTENSION);
        rename($dest . '/' . $filename, $dest . '/barzahlung-script.' . $ext);
        $this->settingsService->set('pdf_barzahlung_bild', 'barzahlung-script.' . $ext);
        $this->session->flash('success', '"Barzahlung"-Bild aktualisiert.');
        $this->redirect('/einstellungen?tab=pdf');
    }

    public function plugins(array $params = []): void
    {
        $this->redirect('/einstellungen#plugins');
    }

    public function enablePlugin(array $params = []): void
    {
        $this->validateCsrf();
        $this->pluginManager->enablePlugin($params['name']);
        $this->session->flash('success', $this->translator->trans('settings.plugin_enabled'));
        $this->redirect('/einstellungen#plugins');
    }

    public function disablePlugin(array $params = []): void
    {
        $this->validateCsrf();
        $this->pluginManager->disablePlugin($params['name']);
        $this->session->flash('success', $this->translator->trans('settings.plugin_disabled'));
        $this->redirect('/einstellungen#plugins');
    }

    public function updater(array $params = []): void
    {
        $this->redirect('/einstellungen#updates');
    }

    public function runMigrations(array $params = []): void
    {
        $this->validateCsrf();

        try {
            $ran = $this->migrationService->runPending();
            $this->session->flash('success', $this->translator->trans('settings.migrations_ran', ['count' => count($ran)]));
        } catch (\Throwable $e) {
            $this->session->flash('error', $this->translator->trans('settings.migrations_failed') . ': ' . $e->getMessage());
        }

        $this->redirect('/einstellungen#updates');
    }

    public function users(array $params = []): void
    {
        $this->redirect('/einstellungen#benutzer');
    }

    public function createUser(array $params = []): void
    {
        $this->validateCsrf();

        $name     = $this->sanitize($this->post('name', ''));
        $email    = $this->sanitize($this->post('email', ''));
        $password = $this->post('password', '');
        $role     = $this->sanitize($this->post('role', 'mitarbeiter'));

        if (empty($name) || empty($email) || empty($password)) {
            $this->session->flash('error', $this->translator->trans('settings.fill_required'));
            $this->redirect('/einstellungen/benutzer');
            return;
        }

        if ($this->userRepository->findByEmail($email)) {
            $this->session->flash('error', $this->translator->trans('settings.email_exists'));
            $this->redirect('/einstellungen/benutzer');
            return;
        }

        $this->userRepository->create([
            'name'     => $name,
            'email'    => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'role'     => $role,
            'active'   => 1,
        ]);

        $this->session->flash('success', $this->translator->trans('settings.user_created'));
        $this->redirect('/einstellungen#benutzer');
    }

    public function updateUser(array $params = []): void
    {
        $this->validateCsrf();

        $id    = (int)$params['id'];
        $name  = $this->sanitize($this->post('name', ''));
        $email = $this->sanitize($this->post('email', ''));
        $role  = in_array($this->post('role'), ['admin', 'mitarbeiter'], true) ? $this->post('role') : 'mitarbeiter';

        if (empty($name) || empty($email)) {
            $this->session->flash('error', $this->translator->trans('settings.fill_required'));
            $this->redirect('/einstellungen/benutzer');
            return;
        }

        $data = ['name' => $name, 'email' => $email, 'role' => $role];

        $password = $this->post('password', '');
        if (!empty($password)) {
            if (strlen($password) < 8) {
                $this->session->flash('error', $this->translator->trans('profile.password_mismatch'));
                $this->redirect('/einstellungen/benutzer');
                return;
            }
            $data['password'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $this->userRepository->update($id, $data);
        $this->session->flash('success', $this->translator->trans('settings.user_updated'));
        $this->redirect('/einstellungen/benutzer');
    }

    public function deleteUser(array $params = []): void
    {
        $this->validateCsrf();

        $currentUserId = (int)$this->session->get('user_id');
        if ((int)$params['id'] === $currentUserId) {
            $this->session->flash('error', $this->translator->trans('settings.cannot_delete_self'));
            $this->redirect('/einstellungen#benutzer');
            return;
        }

        $this->userRepository->delete((int)$params['id']);
        $this->session->flash('success', $this->translator->trans('settings.user_deleted'));
        $this->redirect('/einstellungen#benutzer');
    }

    public function createTreatmentType(array $params = []): void
    {
        $this->validateCsrf();

        $name = $this->sanitize($this->post('name', ''));
        if (empty($name)) {
            $this->session->flash('error', 'Name ist erforderlich.');
            $this->redirect('/einstellungen?tab=behandlungsarten');
            return;
        }

        $this->treatmentTypeRepository->create([
            'name'        => $name,
            'color'       => $this->post('color', '#4f7cff'),
            'price'       => $this->post('price', ''),
            'description' => $this->sanitize($this->post('description', '')),
            'active'      => (int)(bool)$this->post('active', 1),
            'sort_order'  => (int)$this->post('sort_order', 0),
        ]);

        $this->session->flash('success', 'Behandlungsart erstellt.');
        $this->redirect('/einstellungen?tab=behandlungsarten');
    }

    public function updateTreatmentType(array $params = []): void
    {
        $this->validateCsrf();

        $id   = (int)$params['id'];
        $name = $this->sanitize($this->post('name', ''));
        if (empty($name)) {
            $this->session->flash('error', 'Name ist erforderlich.');
            $this->redirect('/einstellungen?tab=behandlungsarten');
            return;
        }

        $this->treatmentTypeRepository->update($id, [
            'name'        => $name,
            'color'       => $this->post('color', '#4f7cff'),
            'price'       => $this->post('price', ''),
            'description' => $this->sanitize($this->post('description', '')),
            'active'      => (int)(bool)$this->post('active', 1),
            'sort_order'  => (int)$this->post('sort_order', 0),
        ]);

        $this->session->flash('success', 'Behandlungsart aktualisiert.');
        $this->redirect('/einstellungen?tab=behandlungsarten');
    }

    public function deleteTreatmentType(array $params = []): void
    {
        $this->validateCsrf();
        $this->treatmentTypeRepository->delete((int)$params['id']);
        $this->session->flash('success', 'Behandlungsart gelöscht.');
        $this->redirect('/einstellungen?tab=behandlungsarten');
    }

    public function treatmentTypesJson(array $params = []): void
    {
        $types = $this->treatmentTypeRepository->findActive();
        header('Content-Type: application/json');
        echo json_encode($types);
        exit;
    }
}
