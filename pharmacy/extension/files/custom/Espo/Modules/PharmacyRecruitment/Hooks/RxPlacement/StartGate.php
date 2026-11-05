<?php

namespace Espo\Modules\PharmacyRecruitment\Hooks\RxPlacement;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\PlacementGate;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class StartGate implements BeforeSave
{
    private const ACTIVE = 'Active';
    private const COMPLETED = 'Completed';
    private const WATCHED = ['status', 'candidateId', 'startDate', 'accountId', 'jobOrderId'];

    public function __construct(private PlacementGate $gate) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($this->needsCheck($entity)) {
            $this->gate->assertPlacementCanStart($entity);
        }
    }

    private function needsCheck(Entity $entity): bool
    {
        $status = $entity->get('status');

        if ($status !== self::ACTIVE && $status !== self::COMPLETED) {
            return false;
        }

        if ($entity->isNew()) {
            return true;
        }

        // A placement may finish after a credential lapses, so finishing is no reason to check. One
        // that never started must not reach Completed unchecked.
        $finishing = $status === self::COMPLETED && $entity->getFetched('status') === self::ACTIVE;

        foreach (array_diff(self::WATCHED, $finishing ? ['status'] : []) as $attribute) {
            if ($entity->isAttributeChanged($attribute)) {
                return true;
            }
        }

        return false;
    }
}
