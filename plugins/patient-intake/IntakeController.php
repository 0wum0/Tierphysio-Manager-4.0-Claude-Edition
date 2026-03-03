<?php

declare(strict_types=1);

namespace Plugins\PatientIntake;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Core\Database;

class IntakeController extends Controller
{
    private IntakeRepository $repo;
    private IntakeMailService $mailer;

    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        Database $db
    ) {
        parent::__construct($view, $session, $config, $translator);
        $this->repo   = new IntakeRepository($db);
        $this->mailer = new IntakeMailService($config);
    }

    /* ─────────────────────────────────────────────────────────
       PUBLIC: Multi-Step Wizard (no auth)
    ───────────────────────────────────────────────────────── */

    public function form(array $params = []): void
    {
        $this->renderPublic('@patient-intake/form.twig', [
            'page_title' => 'Patientenanmeldung',
        ]);
    }

    public function submit(array $params = []): void
    {
        /* Basic honeypot spam protection */
        if (!empty($_POST['website'])) {
            $this->redirect('/anmeldung/danke');
            return;
        }

        $data = $this->buildSubmissionData();
        $errors = $this->validateSubmission($data);

        if (!empty($errors)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'errors' => $errors]);
            exit;
        }

        /* Handle photo upload */
        $photoFilename = '';
        if (!empty($_FILES['patient_photo']['name']) && $_FILES['patient_photo']['error'] === UPLOAD_ERR_OK) {
            $photoFilename = $this->handlePhotoUpload();
        }
        $data['patient_photo'] = $photoFilename;
        $data['ip_address']    = $_SERVER['REMOTE_ADDR'] ?? '';
        $data['created_at']    = date('Y-m-d H:i:s');
        $data['updated_at']    = date('Y-m-d H:i:s');

        $id = $this->repo->create($data);
        $submission = $this->repo->findById($id);

        if ($submission) {
            /* Fire-and-forget notifications */
            try { $this->mailer->sendNewSubmissionNotification($submission); } catch (\Throwable) {}
            try { $this->mailer->sendOwnerConfirmation($submission); } catch (\Throwable) {}
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    public function thankYou(array $params = []): void
    {
        $this->renderPublic('@patient-intake/thankyou.twig', [
            'page_title' => 'Anmeldung erhalten',
        ]);
    }

    /* ─────────────────────────────────────────────────────────
       ADMIN: Eingangsmeldungen
    ───────────────────────────────────────────────────────── */

    public function inbox(array $params = []): void
    {
        $status = $this->get('status', '');
        $page   = max(1, (int)$this->get('page', 1));

        $result = $this->repo->getPaginated($page, 15, $status);

        $counts = [
            'neu'           => $this->repo->countByStatus('neu'),
            'in_bearbeitung'=> $this->repo->countByStatus('in_bearbeitung'),
            'uebernommen'   => $this->repo->countByStatus('uebernommen'),
            'abgelehnt'     => $this->repo->countByStatus('abgelehnt'),
        ];

        $this->render('@patient-intake/inbox.twig', [
            'page_title'   => 'Eingangsmeldungen',
            'submissions'  => $result['items'],
            'pagination'   => $result,
            'counts'       => $counts,
            'active_status'=> $status,
        ]);
    }

    public function show(array $params = []): void
    {
        $submission = $this->repo->findById((int)$params['id']);
        if (!$submission) {
            $this->abort(404);
        }

        /* Auto-mark as in_bearbeitung when opened */
        if ($submission['status'] === 'neu') {
            $this->repo->updateStatus((int)$params['id'], 'in_bearbeitung');
            $submission['status'] = 'in_bearbeitung';
        }

        $this->render('@patient-intake/show.twig', [
            'page_title'  => 'Anmeldung: ' . $submission['patient_name'],
            'submission'  => $submission,
        ]);
    }

    public function accept(array $params = []): void
    {
        /* AJAX: create owner + patient from submission */
        $submission = $this->repo->findById((int)$params['id']);
        if (!$submission) {
            $this->jsonError('Nicht gefunden', 404);
            return;
        }

        try {
            $app = \App\Core\Application::getInstance();
            $db  = $app->getContainer()->get(Database::class);
            $pdo = $db->getPdo();

            /* 1. Find or create owner — use PDO directly to avoid any wrapper issues */
            $stmt = $pdo->prepare("SELECT id FROM owners WHERE email = ? LIMIT 1");
            $stmt->execute([$submission['owner_email']]);
            $existingOwner = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existingOwner) {
                $ownerId = (int)$existingOwner['id'];
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO owners (first_name, last_name, email, phone, street, zip, city, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,NOW(),NOW())"
                );
                $ins->execute([
                    $submission['owner_first_name'],
                    $submission['owner_last_name'],
                    $submission['owner_email'],
                    $submission['owner_phone'],
                    $submission['owner_street'],
                    $submission['owner_zip'],
                    $submission['owner_city'],
                ]);
                $ownerId = (int)$pdo->lastInsertId();
            }

            /* 2. Copy photo from intake storage to patients storage */
            $photoFilename = '';
            if (!empty($submission['patient_photo'])) {
                $src    = STORAGE_PATH . '/intake/' . $submission['patient_photo'];
                $dstDir = STORAGE_PATH . '/patients';
                if (!is_dir($dstDir)) {
                    mkdir($dstDir, 0755, true);
                }
                $dst = $dstDir . '/' . $submission['patient_photo'];
                if (file_exists($src)) {
                    copy($src, $dst);
                    $photoFilename = $submission['patient_photo'];
                }
            }

            /* 3. Create patient — use PDO directly */
            $ins2 = $pdo->prepare(
                "INSERT INTO patients (name, species, breed, gender, birth_date, color, chip_number, owner_id, photo, status, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,'aktiv',NOW(),NOW())"
            );
            $ins2->execute([
                $submission['patient_name'],
                $submission['patient_species'],
                $submission['patient_breed'],
                $submission['patient_gender'],
                $submission['patient_birth_date'] ?: null,
                $submission['patient_color'],
                $submission['patient_chip'],
                $ownerId,
                $photoFilename,
            ]);
            $patientId = (int)$pdo->lastInsertId();

            /* 3. Mark submission as accepted */
            $this->repo->updateStatus((int)$params['id'], 'uebernommen', [
                'accepted_patient_id' => $patientId,
                'accepted_owner_id'   => $ownerId,
            ]);

            header('Content-Type: application/json');
            echo json_encode([
                'ok'         => true,
                'patient_id' => $patientId,
                'owner_id'   => $ownerId,
            ]);
        } catch (\Throwable $e) {
            error_log('[PatientIntake] accept error: ' . $e->getMessage());
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Fehler beim Übernehmen: ' . $e->getMessage()]);
        }
        exit;
    }

    public function reject(array $params = []): void
    {
        $submission = $this->repo->findById((int)$params['id']);
        if (!$submission) {
            $this->jsonError('Nicht gefunden', 404);
            return;
        }

        $this->repo->updateStatus((int)$params['id'], 'abgelehnt');

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    public function updateStatus(array $params = []): void
    {
        $submission = $this->repo->findById((int)$params['id']);
        if (!$submission) {
            $this->jsonError('Nicht gefunden', 404);
            return;
        }

        $allowed = ['neu', 'in_bearbeitung', 'uebernommen', 'abgelehnt'];
        $status  = $_POST['status'] ?? '';

        if (!in_array($status, $allowed, true)) {
            header('Content-Type: application/json');
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Status']);
            exit;
        }

        $this->repo->updateStatus((int)$params['id'], $status);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    /* ─────────────────────────────────────────────────────────
       API: Notification count for header bell
    ───────────────────────────────────────────────────────── */

    public function apiNotifications(array $params = []): void
    {
        try {
            $count  = $this->repo->countUnread();
            $latest = $this->repo->getLatestUnread(5);
            header('Content-Type: application/json');
            echo json_encode(['count' => $count, 'items' => $latest]);
        } catch (\Throwable) {
            header('Content-Type: application/json');
            echo json_encode(['count' => 0, 'items' => []]);
        }
        exit;
    }

    /* ─────────────────────────────────────────────────────────
       Helpers
    ───────────────────────────────────────────────────────── */

    private function renderPublic(string $template, array $data = []): void
    {
        /* Render without auth — uses public layout */
        $this->view->render($template, array_merge([
            'csrf_token' => $this->session->generateCsrfToken(),
        ], $data));
    }

    private function buildSubmissionData(): array
    {
        return [
            'owner_first_name'  => $this->sanitize($this->post('owner_first_name', '')),
            'owner_last_name'   => $this->sanitize($this->post('owner_last_name', '')),
            'owner_email'       => filter_var($this->post('owner_email', ''), FILTER_SANITIZE_EMAIL),
            'owner_phone'       => $this->sanitize($this->post('owner_phone', '')),
            'owner_street'      => $this->sanitize($this->post('owner_street', '')),
            'owner_zip'         => $this->sanitize($this->post('owner_zip', '')),
            'owner_city'        => $this->sanitize($this->post('owner_city', '')),
            'patient_name'      => $this->sanitize($this->post('patient_name', '')),
            'patient_species'   => $this->sanitize($this->post('patient_species', '')),
            'patient_breed'     => $this->sanitize($this->post('patient_breed', '')),
            'patient_gender'    => $this->sanitize($this->post('patient_gender', '')),
            'patient_birth_date'=> $this->post('patient_birth_date') ?: null,
            'patient_color'     => $this->sanitize($this->post('patient_color', '')),
            'patient_chip'      => $this->sanitize($this->post('patient_chip', '')),
            'reason'            => $this->post('reason', ''),
            'appointment_wish'  => $this->sanitize($this->post('appointment_wish', '')),
            'notes'             => $this->post('notes', ''),
            'status'            => 'neu',
        ];
    }

    private function validateSubmission(array $data): array
    {
        $errors = [];

        if (empty($data['owner_first_name'])) $errors[] = 'Vorname des Besitzers ist erforderlich.';
        if (empty($data['owner_last_name']))  $errors[] = 'Nachname des Besitzers ist erforderlich.';
        if (empty($data['owner_email']) || !filter_var($data['owner_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Gültige E-Mail-Adresse ist erforderlich.';
        }
        if (empty($data['owner_phone']))      $errors[] = 'Telefonnummer ist erforderlich.';
        if (empty($data['patient_name']))     $errors[] = 'Name des Tieres ist erforderlich.';
        if (empty($data['patient_species']))  $errors[] = 'Tierart ist erforderlich.';
        if (empty($data['reason']))           $errors[] = 'Bitte beschreiben Sie Ihr Anliegen.';

        return $errors;
    }

    private function handlePhotoUpload(): string
    {
        $allowed   = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $maxSize   = 8 * 1024 * 1024; /* 8 MB */
        $file      = $_FILES['patient_photo'];

        if ($file['size'] > $maxSize) {
            return '';
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, $allowed, true)) {
            return '';
        }

        $ext  = match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'gif',
        };

        $dir = STORAGE_PATH . '/intake';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'intake_' . uniqid() . '.' . $ext;
        $dest     = $dir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            return $filename;
        }

        return '';
    }

    public function servePhoto(array $params = []): void
    {
        $file = basename($this->sanitize($params['file']));
        $path = STORAGE_PATH . '/intake/' . $file;

        if (!file_exists($path) || !is_file($path)) {
            $this->abort(404);
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        if (!str_starts_with($mimeType, 'image/')) {
            $this->abort(403);
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit;
    }

    private function jsonError(string $message, int $code = 400): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    }
}
