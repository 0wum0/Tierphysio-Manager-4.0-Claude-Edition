<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Services\PatientService;
use App\Services\OwnerService;
use App\Services\InvoiceService;
use App\Repositories\TreatmentTypeRepository;
use App\Repositories\SettingsRepository;

class PatientController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly PatientService $patientService,
        private readonly OwnerService $ownerService,
        private readonly TreatmentTypeRepository $treatmentTypeRepository,
        private readonly InvoiceService $invoiceService,
        private readonly SettingsRepository $settingsRepository
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function index(array $params = []): void
    {
        $search  = $this->get('search', '');
        $filter  = $this->get('filter', '');
        $page    = (int)$this->get('page', 1);
        $result  = $this->patientService->getPaginated($page, 12, $search, $filter);

        $treatmentTypes = [];
        try { $treatmentTypes = $this->treatmentTypeRepository->findActive(); } catch (\Throwable) {}
        $settings = $this->settingsRepository->all();
        $owners   = $this->ownerService->findAll();

        $this->render('patients/index.twig', [
            'page_title'       => $this->translator->trans('nav.patients'),
            'patients'         => $result['items'],
            'pagination'       => $result,
            'search'           => $search,
            'filter'           => $filter,
            'owners'           => $owners,
            'treatment_types'  => $treatmentTypes,
            'next_number'      => $this->invoiceService->generateInvoiceNumber(),
            'kleinunternehmer' => ($settings['kleinunternehmer'] ?? '0') === '1',
            'default_tax_rate' => $settings['default_tax_rate'] ?? '19',
        ]);
    }

    public function show(array $params = []): void
    {
        $patient = $this->patientService->findById((int)$params['id']);
        if (!$patient) {
            $this->abort(404);
        }

        $owner    = $this->ownerService->findById((int)$patient['owner_id']);
        $timeline = $this->patientService->getTimeline((int)$params['id']);
        $owners   = $this->ownerService->findAll();

        $treatmentTypes = [];
        try { $treatmentTypes = $this->treatmentTypeRepository->findActive(); } catch (\Throwable) {}

        $settings = $this->settingsRepository->all();

        $this->render('patients/show.twig', [
            'page_title'       => $patient['name'],
            'patient'          => $patient,
            'owner'            => $owner,
            'timeline'         => $timeline,
            'owners'           => $owners,
            'treatment_types'  => $treatmentTypes,
            'next_number'      => $this->invoiceService->generateInvoiceNumber(),
            'kleinunternehmer' => ($settings['kleinunternehmer'] ?? '0') === '1',
            'default_tax_rate' => $settings['default_tax_rate'] ?? '19',
        ]);
    }

    public function showJson(array $params = []): void
    {
        $patient  = $this->patientService->findById((int)$params['id']);
        if (!$patient) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'not found']);
            exit;
        }

        $owner          = $this->ownerService->findById((int)$patient['owner_id']);
        $timeline       = $this->patientService->getTimeline((int)$params['id']);
        $treatmentTypes = [];
        try { $treatmentTypes = $this->treatmentTypeRepository->findActive(); } catch (\Throwable) {}

        header('Content-Type: application/json');
        echo json_encode([
            'patient'         => $patient,
            'owner'           => $owner,
            'timeline'        => $timeline,
            'treatment_types' => $treatmentTypes,
        ]);
        exit;
    }

    public function store(array $params = []): void
    {
        $this->validateCsrf();

        $data = [
            'name'       => $this->sanitize($this->post('name', '')),
            'species'    => $this->sanitize($this->post('species', '')),
            'breed'      => $this->sanitize($this->post('breed', '')),
            'birth_date' => $this->post('birth_date', null),
            'gender'     => $this->sanitize($this->post('gender', '')),
            'color'      => $this->sanitize($this->post('color', '')),
            'chip_number'=> $this->sanitize($this->post('chip_number', '')),
            'owner_id'   => (int)$this->post('owner_id', 0),
            'notes'      => $this->post('notes', ''),
            'status'     => $this->sanitize($this->post('status', 'aktiv')),
        ];

        if (empty($data['name']) || empty($data['owner_id'])) {
            $this->session->flash('error', $this->translator->trans('patients.fill_required'));
            $this->redirect('/patienten');
            return;
        }

        $id = $this->patientService->create($data);
        $this->session->flash('success', $this->translator->trans('patients.created'));
        $this->redirect("/patienten/{$id}");
    }

    public function update(array $params = []): void
    {
        $this->validateCsrf();

        $patient = $this->patientService->findById((int)$params['id']);
        if (!$patient) {
            $this->abort(404);
        }

        $data = [
            'name'       => $this->sanitize($this->post('name', '')),
            'species'    => $this->sanitize($this->post('species', '')),
            'breed'      => $this->sanitize($this->post('breed', '')),
            'birth_date' => $this->post('birth_date', null),
            'gender'     => $this->sanitize($this->post('gender', '')),
            'color'      => $this->sanitize($this->post('color', '')),
            'chip_number'=> $this->sanitize($this->post('chip_number', '')),
            'owner_id'   => (int)$this->post('owner_id', 0),
            'notes'      => $this->post('notes', ''),
            'status'     => $this->sanitize($this->post('status', 'aktiv')),
        ];

        $this->patientService->update((int)$params['id'], $data);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            $this->json(['ok' => true, 'patient' => $this->patientService->findById((int)$params['id'])]);
            return;
        }

        $this->session->flash('success', $this->translator->trans('patients.updated'));
        $this->redirect('/patienten');
    }

    public function delete(array $params = []): void
    {
        $this->validateCsrf();
        $patient = $this->patientService->findById((int)$params['id']);
        if (!$patient) {
            $this->abort(404);
        }

        $this->patientService->delete((int)$params['id']);
        $this->session->flash('success', $this->translator->trans('patients.deleted'));
        $this->redirect('/patienten');
    }

    public function uploadPhoto(array $params = []): void
    {
        $this->validateCsrf();
        $patient = $this->patientService->findById((int)$params['id']);
        if (!$patient) {
            $this->abort(404);
        }

        $destination = STORAGE_PATH . '/patients/' . $params['id'];
        $filename = $this->uploadFile('photo', $destination, [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp'
        ]);

        if ($filename === false) {
            $this->session->flash('error', $this->translator->trans('patients.photo_upload_failed'));
            $this->redirect("/patienten/{$params['id']}");
            return;
        }

        $this->patientService->update((int)$params['id'], ['photo' => $filename]);
        $this->session->flash('success', $this->translator->trans('patients.photo_updated'));
        $this->redirect("/patienten/{$params['id']}");
    }

    public function addTimelineEntry(array $params = []): void
    {
        $this->validateCsrf();
        $patient = $this->patientService->findById((int)$params['id']);
        if (!$patient) {
            $this->abort(404);
        }

        $data = [
            'patient_id'  => (int)$params['id'],
            'type'        => $this->sanitize($this->post('type', 'note')),
            'title'       => $this->sanitize($this->post('title', '')),
            'content'     => $this->post('content', ''),
            'status_badge'=> $this->sanitize($this->post('status_badge', '')),
            'entry_date'  => $this->post('entry_date') ?: date('Y-m-d H:i:s'),
            'user_id'     => (int)$this->session->get('user_id'),
        ];

        $file = null;
        if (!empty($_FILES['attachment']['name'])) {
            $destination = STORAGE_PATH . '/patients/' . $params['id'] . '/timeline';
            if (!is_dir($destination)) {
                mkdir($destination, 0755, true);
            }
            $file = $this->uploadFile('attachment', $destination, [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);
            if ($file) {
                $data['attachment'] = $file;
            }
        }

        $this->patientService->addTimelineEntry($data);
        $this->session->flash('success', $this->translator->trans('patients.timeline_added'));
        $this->redirect("/patienten/{$params['id']}");
    }

    public function addTimelineEntryJson(array $params = []): void
    {
        $this->validateCsrf();
        $patient = $this->patientService->findById((int)$params['id']);
        if (!$patient) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'not found']);
            exit;
        }

        $ttId = $this->post('treatment_type_id', '');
        $data = [
            'patient_id'        => (int)$params['id'],
            'type'              => $this->sanitize($this->post('type', 'note')),
            'treatment_type_id' => $ttId !== '' ? (int)$ttId : null,
            'title'             => $this->sanitize($this->post('title', '')),
            'content'           => $this->post('content', ''),
            'status_badge'      => $this->sanitize($this->post('status_badge', '')),
            'entry_date'        => $this->post('entry_date') ?: date('Y-m-d H:i:s'),
            'user_id'           => (int)$this->session->get('user_id'),
        ];

        if (!empty($_FILES['attachment']['name'])) {
            $destination = STORAGE_PATH . '/patients/' . $params['id'] . '/timeline';
            if (!is_dir($destination)) {
                mkdir($destination, 0755, true);
            }
            $file = $this->uploadFile('attachment', $destination, [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);
            if ($file) {
                $data['attachment'] = $file;
            }
        }

        $this->patientService->addTimelineEntry($data);
        $timeline = $this->patientService->getTimeline((int)$params['id']);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'timeline' => $timeline]);
        exit;
    }

    public function deleteTimelineEntry(array $params = []): void
    {
        $this->validateCsrf();
        $this->patientService->deleteTimelineEntry((int)$params['entryId']);
        $this->session->flash('success', $this->translator->trans('patients.timeline_deleted'));
        $this->redirect("/patienten/{$params['id']}");
    }

    public function uploadDocument(array $params = []): void
    {
        $this->validateCsrf();
        $patient = $this->patientService->findById((int)$params['id']);
        if (!$patient) {
            $this->abort(404);
        }

        $destination = STORAGE_PATH . '/patients/' . $params['id'] . '/docs';
        $filename = $this->uploadFile('document', $destination);

        if ($filename === false) {
            $this->session->flash('error', $this->translator->trans('patients.upload_failed'));
        } else {
            $this->session->flash('success', $this->translator->trans('patients.uploaded'));
        }
        $this->redirect("/patienten/{$params['id']}");
    }

    public function downloadDocument(array $params = []): void
    {
        $patient = $this->patientService->findById((int)$params['id']);
        if (!$patient) {
            $this->abort(404);
        }

        $file = basename($this->sanitize($params['file']));
        $path = STORAGE_PATH . '/patients/' . (int)$params['id'] . '/timeline/' . $file;

        if (!file_exists($path) || !is_file($path)) {
            $this->abort(404);
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        $isInline = str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: ' . ($isInline ? 'inline' : 'attachment') . '; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

    public function servePhoto(array $params = []): void
    {
        $file = basename($this->sanitize($params['file']));

        /* Check multiple locations: per-patient folder, flat patients dir, intake dir */
        $candidates = [
            STORAGE_PATH . '/patients/' . (int)$params['id'] . '/' . $file,
            STORAGE_PATH . '/patients/' . $file,
            STORAGE_PATH . '/intake/' . $file,
        ];

        $path = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate) && is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }

        if ($path === null) {
            $this->abort(404);
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        if (!str_starts_with($mimeType, 'image/')) {
            $this->abort(403);
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }
}
