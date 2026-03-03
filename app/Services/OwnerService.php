<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OwnerRepository;

class OwnerService
{
    public function __construct(
        private readonly OwnerRepository $ownerRepository
    ) {}

    public function findById(int $id): array|false
    {
        return $this->ownerRepository->findById($id);
    }

    public function findAll(): array
    {
        return $this->ownerRepository->findAll();
    }

    public function getPaginated(int $page, int $perPage, string $search = ''): array
    {
        return $this->ownerRepository->getPaginated($page, $perPage, $search);
    }

    public function create(array $data): string
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->ownerRepository->create($data);
    }

    public function update(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->ownerRepository->update($id, $data);
    }

    public function delete(int $id): void
    {
        $this->ownerRepository->delete($id);
    }
}
