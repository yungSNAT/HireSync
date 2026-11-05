<?php

namespace Espo\Modules\PharmacyRecruitment\Hooks\RxCredential;

use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\RetentionHold;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

class RetentionGuard implements BeforeRemove, BeforeSave
{
    public function __construct(private RetentionHold $hold) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew() && ($entity->isAttributeChanged('type') || $entity->isAttributeChanged('candidateId'))) {
            $this->hold->assertCredentialRemovable($entity);
        }
    }

    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->hold->assertCredentialRemovable($entity);
    }
}
