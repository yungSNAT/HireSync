<?php

namespace Espo\Modules\PharmacyRecruitment\Hooks\RxShift;

use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\RetentionHold;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

class KeepWorkDate implements BeforeSave, BeforeRemove
{
    public function __construct(private RetentionHold $hold) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->hold->keepWorkDate($entity);
    }

    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->hold->keepWorkDate($entity);
    }
}
