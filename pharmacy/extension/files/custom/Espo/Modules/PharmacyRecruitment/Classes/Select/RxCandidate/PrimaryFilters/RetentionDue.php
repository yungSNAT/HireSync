<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Select\RxCandidate\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\CandidateCompliance;
use Espo\ORM\Query\SelectBuilder;

class RetentionDue implements Filter
{
    public function __construct(private CandidateCompliance $compliance) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['retentionReviewDate<=' => $this->compliance->today()]);
    }
}
