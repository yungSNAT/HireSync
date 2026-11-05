<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Select\RxShift\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

class AtRisk implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['atRisk' => true]);
    }
}
