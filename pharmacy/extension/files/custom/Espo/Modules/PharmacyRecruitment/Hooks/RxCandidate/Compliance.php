<?php

namespace Espo\Modules\PharmacyRecruitment\Hooks\RxCandidate;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\CandidateCompliance;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\RetentionHold;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

class Compliance implements BeforeSave, BeforeRemove, AfterRemove
{
    public function __construct(
        private EntityManager $entityManager,
        private CandidateCompliance $compliance,
        private RetentionHold $hold,
        private AclManager $aclManager,
        private User $user,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew() || $entity->isAttributeChanged('profession')) {
            $this->assertRoleCanChange($entity);
            $this->compliance->apply($entity);
        }
    }

    /**
     * The role counts on all of this person's work, so lowering it is a compliance decision.
     *
     * @throws Forbidden
     */
    private function assertRoleCanChange(Entity $entity): void
    {
        if ($entity->isNew() || $this->aclManager->checkScope($this->user, 'RxCredential', 'edit')) {
            return;
        }

        $label = 'rxCandidateRoleLocked';

        throw Forbidden::createWithBody($label, Body::create()->withMessageTranslation($label, 'RxCandidate'));
    }

    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->hold->assertCandidateRemovable($entity);
    }

    /**
     * Credentials and their evidence must not outlive the person they describe.
     */
    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        $credentials = $this->entityManager
            ->getRDBRepository('RxCredential')
            ->where(['candidateId' => $entity->getId()])
            ->find();

        foreach ($credentials as $credential) {
            $this->entityManager->removeEntity($credential);
        }
    }
}
