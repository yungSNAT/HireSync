<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Select\RxShift\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\CandidateCompliance;
use Espo\ORM\Query\SelectBuilder;

class Unfilled implements Filter
{
    public function __construct(private CandidateCompliance $compliance) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where([
            'status' => ['Open', 'Offered'],
            'dateStart>=' => $this->compliance->dayStartUtc(),
        ]);
    }
}
