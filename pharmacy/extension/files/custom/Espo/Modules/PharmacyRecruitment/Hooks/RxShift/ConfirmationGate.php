<?php

namespace Espo\Modules\PharmacyRecruitment\Hooks\RxShift;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\PlacementGate;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ConfirmationGate implements BeforeSave
{
    private const CONFIRMED = 'Confirmed';
    private const COMPLETED = 'Completed';
    private const WATCHED = ['status', 'candidateId', 'dateStart', 'dateEnd', 'profession', 'accountId', 'jobOrderId'];

    public function __construct(private PlacementGate $gate) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($this->needsCheck($entity)) {
            $this->gate->assertShiftCanConfirm($entity);
            $entity->set('atRisk', false);
        }

        if ($entity->get('status') !== self::CONFIRMED) {
            $entity->set('atRisk', false);
        }
    }

    private function needsCheck(Entity $entity): bool
    {
        $status = $entity->get('status');

        if ($status !== self::CONFIRMED && $status !== self::COMPLETED) {
            return false;
        }

        if ($entity->isNew()) {
            return true;
        }

        // A shift that was confirmed may finish after a credential lapses, so finishing is no reason to
        // check. One that skipped confirmation must not reach Completed unchecked.
        $finishing = $status === self::COMPLETED && $entity->getFetched('status') === self::CONFIRMED;

        foreach (array_diff(self::WATCHED, $finishing ? ['status'] : []) as $attribute) {
            if ($entity->isAttributeChanged($attribute)) {
                return true;
            }
        }

        return false;
    }
}
