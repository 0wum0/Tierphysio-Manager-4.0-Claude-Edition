<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PatientRepository;

class PatientService
{
    public function __construct(
        private readonly PatientRepository $patientRepository
    ) {}

    public function findById(int $id): array|false
    {
        return $this->patientRepository->findById($id);
    }

    public function findAll(): array
    {
        return $this->patientRepository->findAll('name');
    }

    public function findByOwner(int $ownerId): array
    {
        return $this->patientRepository->findByOwner($ownerId);
    }

    public function getPaginated(int $page, int $perPage, string $search = '', string $filter = ''): array
    {
        return $this->patientRepository->getPaginated($page, $perPage, $search, $filter);
    }

    public function create(array $data): string
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->patientRepository->create($data);
    }

    public function update(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->patientRepository->update($id, $data);
    }

    public function delete(int $id): void
    {
        $this->patientRepository->delete($id);
    }

    public function getTimeline(int $patientId): array
    {
        return $this->patientRepository->getTimeline($patientId);
    }

    public function addTimelineEntry(array $data): void
    {
        $this->patientRepository->addTimelineEntry($data);
    }

    public function deleteTimelineEntry(int $entryId): void
    {
        $this->patientRepository->deleteTimelineEntry($entryId);
    }
}
