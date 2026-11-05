<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Select\RxJobOrder\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

class Open implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['status' => 'Open']);
    }
}
