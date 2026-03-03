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

class PatientController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly PatientService $patientService,
        private readonly OwnerService $ownerService
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function index(array $params = []): void
    {
        $search  = $this->get('search', '');
        $filter  = $this->get('filter', '');
        $page    = (int)$this->get('page', 1);
        $result  = $this->patientService->getPaginated($page, 12, $search, $filter);

        $this->render('patients/index.twig', [
            'page_title' => $this->translator->trans('nav.patients'),
            'patients'   => $result['items'],
            'pagination' => $result,
            'search'     => $search,
            'filter'     => $filter,
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

        $this->render('patients/show.twig', [
            'page_title' => $patient['name'],
            'patient'    => $patient,
            'owner'      => $owner,
            'timeline'   => $timeline,
            'owners'     => $owners,
        ]);
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
        $this->session->flash('success', $this->translator->trans('patients.updated'));
        $this->redirect("/patienten/{$params['id']}");
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
            $file = $this->uploadFile('attachment', $destination, [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ]);
            if ($file) {
                $data['attachment'] = $file;
            }
        }

        $this->patientService->addTimelineEntry($data);
        $this->session->flash('success', $this->translator->trans('patients.timeline_added'));
        $this->redirect("/patienten/{$params['id']}");
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

        $file = $this->sanitize($params['file']);
        $path = STORAGE_PATH . '/patients/' . $params['id'] . '/docs/' . $file;

        if (!file_exists($path) || !is_file($path)) {
            $this->abort(404);
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
