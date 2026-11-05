<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Select\RxShift\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\CandidateCompliance;
use Espo\ORM\Query\SelectBuilder;

class NextSevenDays implements Filter
{
    public function __construct(private CandidateCompliance $compliance) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where([
            'status!=' => 'Cancelled',
            'dateStart>=' => $this->compliance->dayStartUtc(),
            'dateStart<' => $this->compliance->dayStartUtc(8),
        ]);
    }
}
