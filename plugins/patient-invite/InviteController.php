<?php

declare(strict_types=1);

namespace Plugins\PatientInvite;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Core\Database;
use App\Core\Application;
use App\Repositories\SettingsRepository;
use App\Services\MailService;

class InviteController extends Controller
{
    private InviteRepository  $repo;
    private InviteMailService $mailer;
    private SettingsRepository $settingsRepository;

    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        Database $db,
        SettingsRepository $settingsRepository,
        MailService $mailService
    ) {
        parent::__construct($view, $session, $config, $translator);
        $this->repo               = new InviteRepository($db);
        $this->settingsRepository = $settingsRepository;
        $this->mailer             = new InviteMailService($settingsRepository, $mailService);
    }

    /* ─────────────────────────────────────────────────────────
       ADMIN: Einladung erstellen & senden
    ───────────────────────────────────────────────────────── */

    public function index(array $params = []): void
    {
        $page   = max(1, (int)$this->get('page', 1));
        $result = $this->repo->getPaginated($page, 20);

        $this->render('@patient-invite/index.twig', [
            'page_title'   => 'Einladungslinks',
            'submissions'  => $result['items'],
            'pagination'   => $result,
            'counts'       => [
                'offen'      => $this->repo->countByStatus('offen'),
                'angenommen' => $this->repo->countByStatus('angenommen'),
                'abgelaufen' => $this->repo->countByStatus('abgelaufen'),
            ],
        ]);
    }

    public function send(array $params = []): void
    {
        $email   = trim($this->post('email', ''));
        $phone   = trim($this->post('phone', ''));
        $note    = trim($this->post('note', ''));
        $via     = $this->post('via', 'email'); /* email | whatsapp | both */

        /* Validate */
        $errors = [];
        if ($via !== 'whatsapp' && (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors[] = 'Gültige E-Mail-Adresse erforderlich.';
        }
        if (in_array($via, ['whatsapp', 'both'], true) && empty($phone)) {
            $errors[] = 'Telefonnummer für WhatsApp erforderlich.';
        }

        if (!empty($errors)) {
            $this->session->flash('error', implode(' ', $errors));
            $this->redirect('/einladungen');
            return;
        }

        /* Generate token */
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        $user      = $this->session->getUser();

        $this->repo->create([
            'token'      => $token,
            'email'      => $email,
            'phone'      => $phone,
            'note'       => $note,
            'status'     => 'offen',
            'sent_via'   => $via,
            'created_by' => $user['id'] ?? null,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $appUrl    = rtrim($_ENV['APP_URL'] ?? '', '/');
        $inviteUrl = $appUrl . '/einladung/' . $token;

        /* Send */
        if (in_array($via, ['email', 'both'], true) && !empty($email)) {
            $sent = $this->mailer->sendInviteEmail($email, $inviteUrl, $note);
            if (!$sent) {
                $this->session->flash('warning', 'Einladung erstellt, aber E-Mail konnte nicht gesendet werden. Link: ' . $inviteUrl);
                $this->redirect('/einladungen');
                return;
            }
        }

        $this->session->flash('success', 'Einladung wurde erfolgreich versendet.');
        $this->redirect('/einladungen');
    }

    /* Returns the WhatsApp URL as JSON for the UI to open */
    public function whatsappUrl(array $params = []): void
    {
        $invite = $this->repo->findById((int)$params['id']);
        if (!$invite) {
            $this->json(['ok' => false, 'error' => 'Nicht gefunden'], 404);
            return;
        }

        $appUrl    = rtrim($_ENV['APP_URL'] ?? '', '/');
        $inviteUrl = $appUrl . '/einladung/' . $invite['token'];
        $appName   = $this->settingsRepository->get('company_name', 'Tierphysio Manager');
        $waUrl     = $this->mailer->buildWhatsAppUrl($invite['phone'], $inviteUrl, $appName);

        $this->json(['ok' => true, 'url' => $waUrl]);
    }

    public function copyLink(array $params = []): void
    {
        $invite = $this->repo->findById((int)$params['id']);
        if (!$invite) {
            $this->json(['ok' => false, 'error' => 'Nicht gefunden'], 404);
            return;
        }
        $appUrl    = rtrim($_ENV['APP_URL'] ?? '', '/');
        $inviteUrl = $appUrl . '/einladung/' . $invite['token'];
        $this->json(['ok' => true, 'url' => $inviteUrl]);
    }

    public function revoke(array $params = []): void
    {
        $invite = $this->repo->findById((int)$params['id']);
        if (!$invite) {
            $this->json(['ok' => false, 'error' => 'Nicht gefunden'], 404);
            return;
        }

        $db  = Application::getInstance()->getContainer()->get(Database::class);
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare("UPDATE `patient_invite_tokens` SET status = 'abgelaufen' WHERE id = ?");
        $stmt->execute([$params['id']]);

        $this->json(['ok' => true]);
    }

    public function acceptAdmin(array $params = []): void
    {
        $this->validateCsrf();
        $invite = $this->repo->findById((int)$params['id']);
        if (!$invite) {
            $this->json(['ok' => false, 'error' => 'Nicht gefunden'], 404);
            return;
        }
        if ($invite['status'] !== 'offen') {
            $this->json(['ok' => false, 'error' => 'Einladung ist nicht mehr offen'], 422);
            return;
        }

        $db  = Application::getInstance()->getContainer()->get(Database::class);
        $pdo = $db->getPdo();
        $pdo->prepare(
            "UPDATE `patient_invite_tokens` SET status = 'angenommen', accepted_at = NOW() WHERE id = ?"
        )->execute([$params['id']]);

        $this->json(['ok' => true]);
    }

    public function rejectAdmin(array $params = []): void
    {
        $this->validateCsrf();
        $invite = $this->repo->findById((int)$params['id']);
        if (!$invite) {
            $this->json(['ok' => false, 'error' => 'Nicht gefunden'], 404);
            return;
        }
        if ($invite['status'] !== 'offen') {
            $this->json(['ok' => false, 'error' => 'Einladung ist nicht mehr offen'], 422);
            return;
        }

        $db  = Application::getInstance()->getContainer()->get(Database::class);
        $pdo = $db->getPdo();
        $pdo->prepare(
            "UPDATE `patient_invite_tokens` SET status = 'abgelaufen' WHERE id = ?"
        )->execute([$params['id']]);

        $this->json(['ok' => true]);
    }

    /* ─────────────────────────────────────────────────────────
       PUBLIC: Magic Link Landing — Besitzer klickt den Link
    ───────────────────────────────────────────────────────── */

    public function landing(array $params = []): void
    {
        $token  = $params['token'] ?? '';
        $invite = $this->repo->findByToken($token);

        /* Expired or not found */
        if (!$invite || !$this->repo->isTokenValid($token)) {
            $this->renderPublic('@patient-invite/invalid.twig', [
                'page_title' => 'Link ungültig',
                'invite'     => $invite,
            ]);
            return;
        }

        /* Already used */
        if ($invite['status'] === 'angenommen') {
            $this->renderPublic('@patient-invite/already_used.twig', [
                'page_title' => 'Bereits registriert',
            ]);
            return;
        }

        /* Show the pre-filled multi-step form */
        $this->renderPublic('@patient-invite/form.twig', [
            'page_title' => 'Anmeldung',
            'token'      => $token,
            'invite'     => $invite,
            'csrf_token' => $this->session->generateCsrfToken(),
        ]);
    }

    public function submit(array $params = []): void
    {
        /* Detect AJAX: X-Requested-With may be stripped by some Apache configs */
        $wantsJson = $this->isAjax()
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

        $token  = $params['token'] ?? '';
        error_log('[PatientInvite] submit() called, token=' . substr($token, 0, 8) . '... wantsJson=' . ($wantsJson ? 'yes' : 'no') . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
        $invite = $this->repo->findByToken($token);

        if (!$invite || !$this->repo->isTokenValid($token)) {
            http_response_code(410);
            $this->renderPublic('@patient-invite/invalid.twig', [
                'page_title' => 'Link ungültig',
                'invite'     => $invite,
            ]);
            return;
        }

        /* Validate input */
        $data   = $this->buildFormData();
        $errors = $this->validateFormData($data);

        if (!empty($errors)) {
            if ($wantsJson) {
                $this->json(['ok' => false, 'errors' => $errors], 422);
                return;
            }
            $this->renderPublic('@patient-invite/form.twig', [
                'page_title' => 'Anmeldung',
                'token'      => $token,
                'invite'     => $invite,
                'errors'     => $errors,
                'old'        => $data,
                'csrf_token' => $this->session->generateCsrfToken(),
            ]);
            return;
        }

        /* Photo upload */
        $photoFilename = '';
        if (isset($_FILES['patient_photo']) && $_FILES['patient_photo']['error'] === UPLOAD_ERR_OK) {
            $photoFilename = $this->handlePhotoUpload();
        }

        try {
            $db  = Application::getInstance()->getContainer()->get(Database::class);
            $pdo = $db->getPdo();
            $pdo->beginTransaction();

            /* 1. Find or create owner */
            $stmt = $pdo->prepare("SELECT id FROM owners WHERE email = ? LIMIT 1");
            $stmt->execute([$data['owner_email']]);
            $existingOwner = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existingOwner) {
                $ownerId = (int)$existingOwner['id'];
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO owners (first_name, last_name, email, phone, street, zip, city, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,NOW(),NOW())"
                );
                $ins->execute([
                    $data['owner_first_name'],
                    $data['owner_last_name'],
                    $data['owner_email'],
                    $data['owner_phone'],
                    $data['owner_street'] ?? '',
                    $data['owner_zip']    ?? '',
                    $data['owner_city']   ?? '',
                ]);
                $ownerId = (int)$pdo->lastInsertId();
            }

            /* 2. Copy photo to patients storage */
            $patientPhoto = '';
            if (!empty($photoFilename)) {
                try {
                    $src    = STORAGE_PATH . '/intake/' . $photoFilename;
                    $dstDir = STORAGE_PATH . '/patients';
                    if (!is_dir($dstDir)) mkdir($dstDir, 0755, true);
                    $dst = $dstDir . '/' . $photoFilename;
                    if (file_exists($src)) {
                        copy($src, $dst);
                        $patientPhoto = $photoFilename;
                    }
                } catch (\Throwable) {
                    /* Photo copy failed — continue without photo */
                }
            }

            /* 3. Create patient — directly active */
            $ins2 = $pdo->prepare(
                "INSERT INTO patients (name, species, breed, gender, birth_date, color, chip_number, owner_id, photo, status, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,'aktiv',NOW(),NOW())"
            );
            $allowedGenders = ['männlich', 'weiblich', 'kastriert', 'sterilisiert', 'unbekannt'];
            $gender = in_array($data['patient_gender'] ?? '', $allowedGenders, true)
                ? $data['patient_gender']
                : 'unbekannt';

            $ins2->execute([
                $data['patient_name'],
                $data['patient_species'],
                $data['patient_breed']     ?? '',
                $gender,
                $data['patient_birth_date'] ?: null,
                $data['patient_color']     ?? '',
                $data['patient_chip']      ?? '',
                $ownerId,
                $patientPhoto,
            ]);
            $patientId = (int)$pdo->lastInsertId();

            $pdo->commit();

            /* 4. Mark token as used — separate try so DB data is already saved */
            try {
                $this->repo->accept($token, $patientId, $ownerId);
            } catch (\Throwable $e2) {
                /* accept() failed (e.g. column missing) — try minimal status update */
                error_log('[PatientInvite] accept() failed: ' . $e2->getMessage());
                try {
                    $pdo->prepare("UPDATE `patient_invite_tokens` SET status = 'angenommen' WHERE token = ?")
                        ->execute([$token]);
                } catch (\Throwable) {}
            }

        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[PatientInvite] submit error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            if ($wantsJson) {
                $this->json(['ok' => false, 'error' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'], 500);
                return;
            }
            $this->renderPublic('@patient-invite/form.twig', [
                'page_title' => 'Anmeldung',
                'token'      => $token,
                'invite'     => $invite,
                'errors'     => ['Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut.'],
                'old'        => $data,
                'csrf_token' => $this->session->generateCsrfToken(),
            ]);
            return;
        }

        if ($wantsJson) {
            $this->json(['ok' => true, 'redirect' => '/einladung/' . $token . '/danke']);
            return;
        }
        $this->redirect('/einladung/' . $token . '/danke');
    }

    public function testSubmit(array $params = []): void
    {
        $token  = $params['token'] ?? '';
        $invite = $this->repo->findByToken($token);
        $valid  = $this->repo->isTokenValid($token);

        $result = [
            'token_found'  => (bool)$invite,
            'token_valid'  => $valid,
            'token_status' => $invite['status'] ?? null,
            'token_expires'=> $invite['expires_at'] ?? null,
            'server_time'  => date('Y-m-d H:i:s'),
        ];

        if ($invite && $valid) {
            try {
                $db  = Application::getInstance()->getContainer()->get(Database::class);
                $pdo = $db->getPdo();
                $result['db_connected'] = true;

                /* Dry-run owner lookup */
                $stmt = $pdo->prepare("SELECT id FROM owners WHERE email = ? LIMIT 1");
                $stmt->execute(['__dryrun_test@example.com']);
                $result['owners_query_ok'] = true;

                /* Check patients table columns */
                $cols = array_column($pdo->query("SHOW COLUMNS FROM `patients`")->fetchAll(\PDO::FETCH_ASSOC), 'Field');
                $result['patients_columns'] = $cols;

            } catch (\Throwable $e) {
                $result['db_error'] = $e->getMessage();
            }
        }

        $this->json($result);
    }

    public function diagnose(array $params = []): void
    {
        $db  = Application::getInstance()->getContainer()->get(Database::class);
        $pdo = $db->getPdo();

        $cols = $pdo->query("SHOW COLUMNS FROM `patient_invite_tokens`")->fetchAll(\PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'Field');

        $recent = $pdo->query("SELECT id, token, status, accepted_patient_id, accepted_owner_id, expires_at, accepted_at FROM `patient_invite_tokens` ORDER BY id DESC LIMIT 5")->fetchAll(\PDO::FETCH_ASSOC);

        $this->json([
            'table_columns'     => $colNames,
            'has_accepted_cols' => in_array('accepted_patient_id', $colNames),
            'recent_tokens'     => $recent,
            'php_version'       => PHP_VERSION,
            'storage_writable'  => is_writable(STORAGE_PATH),
        ]);
    }

    public function thankYou(array $params = []): void
    {
        $token  = $params['token'] ?? '';
        $invite = $this->repo->findByToken($token);

        $this->renderPublic('@patient-invite/thankyou.twig', [
            'page_title' => 'Willkommen!',
            'invite'     => $invite,
        ]);
    }

    /* ─────────────────────────────────────────────────────────
       Helpers
    ───────────────────────────────────────────────────────── */

    private function renderPublic(string $template, array $data = []): void
    {
        $this->view->render($template, array_merge([
            'csrf_token' => $this->session->generateCsrfToken(),
            'app_name'   => $this->settingsRepository->get('company_name', 'Tierphysio Manager'),
        ], $data));
    }

    private function buildFormData(): array
    {
        return [
            'owner_first_name'   => $this->sanitize($this->post('owner_first_name', '')),
            'owner_last_name'    => $this->sanitize($this->post('owner_last_name', '')),
            'owner_email'        => filter_var($this->post('owner_email', ''), FILTER_SANITIZE_EMAIL),
            'owner_phone'        => $this->sanitize($this->post('owner_phone', '')),
            'owner_street'       => $this->sanitize($this->post('owner_street', '')),
            'owner_zip'          => $this->sanitize($this->post('owner_zip', '')),
            'owner_city'         => $this->sanitize($this->post('owner_city', '')),
            'patient_name'       => $this->sanitize($this->post('patient_name', '')),
            'patient_species'    => $this->sanitize($this->post('patient_species', '')),
            'patient_breed'      => $this->sanitize($this->post('patient_breed', '')),
            'patient_gender'     => $this->sanitize($this->post('patient_gender', '')),
            'patient_birth_date' => $this->post('patient_birth_date') ?: null,
            'patient_color'      => $this->sanitize($this->post('patient_color', '')),
            'patient_chip'       => $this->sanitize($this->post('patient_chip', '')),
        ];
    }

    private function validateFormData(array $data): array
    {
        $errors = [];
        if (empty($data['owner_first_name'])) $errors[] = 'Vorname des Besitzers ist erforderlich.';
        if (empty($data['owner_last_name']))  $errors[] = 'Nachname des Besitzers ist erforderlich.';
        if (empty($data['owner_email']) || !filter_var($data['owner_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Gültige E-Mail-Adresse ist erforderlich.';
        }
        if (empty($data['owner_phone']))   $errors[] = 'Telefonnummer ist erforderlich.';
        if (empty($data['patient_name']))  $errors[] = 'Name des Tieres ist erforderlich.';
        if (empty($data['patient_species'])) $errors[] = 'Tierart ist erforderlich.';
        return $errors;
    }

    private function handlePhotoUpload(): string
    {
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $file    = $_FILES['patient_photo'];
        if ($file['size'] > 8 * 1024 * 1024) return '';

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, $allowed, true)) return '';

        $ext = match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'gif',
        };

        $dir = STORAGE_PATH . '/intake';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = 'invite_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            return $filename;
        }
        return '';
    }
}
