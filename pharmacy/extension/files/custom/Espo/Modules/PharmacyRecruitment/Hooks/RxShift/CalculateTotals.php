<?php

namespace Espo\Modules\PharmacyRecruitment\Hooks\RxShift;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\PharmacyRecruitment\Classes\Shift\Totals;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class CalculateTotals implements BeforeSave
{
    public function __construct(private Totals $totals) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $seconds = strtotime($entity->get('dateEnd') . ' UTC') - strtotime($entity->get('dateStart') . ' UTC');

        // The field's `after` rule runs in the browser only.
        if ($seconds <= 0) {
            throw BadRequest::createWithBody(
                'rxShiftEndsBeforeStart',
                Body::create()->withMessageTranslation('rxShiftEndsBeforeStart', 'RxShift')
            );
        }

        $minutes = $this->totals->paidMinutes($seconds, (int) $entity->get('breakMinutes'));

        $pay = $this->total($entity, 'payRate', $minutes);
        $charge = $this->total($entity, 'chargeRate', $minutes);

        $entity->setMultiple([
            'paidHours' => round($minutes / 60, 2),
            'payTotal' => $pay,
            'payTotalCurrency' => $entity->get('payRateCurrency'),
            'chargeTotal' => $charge,
            'chargeTotalCurrency' => $entity->get('chargeRateCurrency'),
            'margin' => $pay === null || $charge === null ? null : $this->totals->margin($charge, $pay),
            'marginCurrency' => $entity->get('chargeRateCurrency'),
        ]);
    }

    private function total(Entity $entity, string $rateField, int $minutes): ?float
    {
        $rate = $entity->get($rateField);

        return $rate === null ? null : $this->totals->total($minutes, (float) $rate);
    }
}
