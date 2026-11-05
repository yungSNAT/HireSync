<?php

namespace Espo\Modules\PharmacyRecruitment\Hooks\RxCredential;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\CandidateCompliance;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

class RefreshCandidate implements AfterSave, AfterRemove
{
    public function __construct(
        private EntityManager $entityManager,
        private CandidateCompliance $compliance,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        $this->refresh($entity);

        $previousId = $entity->getFetched('candidateId');

        if ($previousId && $previousId !== $entity->get('candidateId')) {
            $this->refreshById($previousId);
        }
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->refresh($entity);
    }

    private function refresh(Entity $credential): void
    {
        $this->refreshById($credential->get('candidateId'));
    }

    private function refreshById(?string $candidateId): void
    {
        $candidate = $candidateId ? $this->entityManager->getEntityById('RxCandidate', $candidateId) : null;

        if ($candidate) {
            $this->compliance->refresh($candidate);
        }
    }
}
