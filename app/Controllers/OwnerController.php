<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Services\OwnerService;
use App\Services\PatientService;

class OwnerController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly OwnerService $ownerService,
        private readonly PatientService $patientService
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function index(array $params = []): void
    {
        $search = $this->get('search', '');
        $page   = (int)$this->get('page', 1);
        $result = $this->ownerService->getPaginated($page, 15, $search);

        $this->render('owners/index.twig', [
            'page_title' => $this->translator->trans('nav.owners'),
            'owners'     => $result['items'],
            'pagination' => $result,
            'search'     => $search,
        ]);
    }

    public function show(array $params = []): void
    {
        $owner = $this->ownerService->findById((int)$params['id']);
        if (!$owner) {
            $this->abort(404);
        }

        $animals  = $this->patientService->findByOwner((int)$params['id']);

        $this->render('owners/show.twig', [
            'page_title' => $owner['first_name'] . ' ' . $owner['last_name'],
            'owner'      => $owner,
            'animals'    => $animals,
        ]);
    }

    public function store(array $params = []): void
    {
        $this->validateCsrf();

        $data = [
            'first_name' => $this->sanitize($this->post('first_name', '')),
            'last_name'  => $this->sanitize($this->post('last_name', '')),
            'email'      => $this->sanitize($this->post('email', '')),
            'phone'      => $this->sanitize($this->post('phone', '')),
            'birth_date' => $this->post('birth_date', null) ?: null,
            'street'     => $this->sanitize($this->post('street', '')),
            'zip'        => $this->sanitize($this->post('zip', '')),
            'city'       => $this->sanitize($this->post('city', '')),
            'notes'      => $this->post('notes', ''),
        ];

        if (empty($data['first_name']) || empty($data['last_name'])) {
            $this->session->flash('error', $this->translator->trans('owners.fill_required'));
            $this->redirect('/tierhalter');
            return;
        }

        $ownerId = $this->ownerService->create($data);

        if ($this->post('create_animal') === '1') {
            $animalData = [
                'name'    => $this->sanitize($this->post('animal_name', '')),
                'species' => $this->sanitize($this->post('animal_species', '')),
                'breed'   => $this->sanitize($this->post('animal_breed', '')),
                'owner_id'=> (int)$ownerId,
                'status'  => 'aktiv',
            ];

            if (!empty($animalData['name'])) {
                $this->patientService->create($animalData);
            }
        }

        $this->session->flash('success', $this->translator->trans('owners.created'));
        $this->redirect("/tierhalter/{$ownerId}");
    }

    public function update(array $params = []): void
    {
        $this->validateCsrf();

        $owner = $this->ownerService->findById((int)$params['id']);
        if (!$owner) {
            $this->abort(404);
        }

        $data = [
            'first_name' => $this->sanitize($this->post('first_name', '')),
            'last_name'  => $this->sanitize($this->post('last_name', '')),
            'email'      => $this->sanitize($this->post('email', '')),
            'phone'      => $this->sanitize($this->post('phone', '')),
            'birth_date' => $this->post('birth_date', null) ?: null,
            'street'     => $this->sanitize($this->post('street', '')),
            'zip'        => $this->sanitize($this->post('zip', '')),
            'city'       => $this->sanitize($this->post('city', '')),
            'notes'      => $this->post('notes', ''),
        ];

        $this->ownerService->update((int)$params['id'], $data);
        $this->session->flash('success', $this->translator->trans('owners.updated'));
        $this->redirect("/tierhalter/{$params['id']}");
    }

    public function delete(array $params = []): void
    {
        $this->validateCsrf();
        $this->requireAdmin();

        $owner = $this->ownerService->findById((int)$params['id']);
        if (!$owner) {
            $this->abort(404);
        }

        $this->ownerService->delete((int)$params['id']);
        $this->session->flash('success', $this->translator->trans('owners.deleted'));
        $this->redirect('/tierhalter');
    }
}
