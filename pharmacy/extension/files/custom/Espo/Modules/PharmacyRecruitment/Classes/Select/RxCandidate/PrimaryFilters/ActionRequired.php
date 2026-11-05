<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Select\RxCandidate\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

class ActionRequired implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['complianceStatus' => ['Blocked', 'Expiring']]);
    }
}
