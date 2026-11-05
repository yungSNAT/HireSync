<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Select\RxCredential\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

class PendingVerification implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['status' => 'Pending']);
    }
}
