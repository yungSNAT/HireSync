<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Compliance;

use DateTimeImmutable;
use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Stops the deletion of a record the law says must outlive the work, using the periods in
 * `app > rxCompliance > retentionYearsAfterWork`.
 */
class RetentionHold
{
    private const WORKED = ['RxShift' => ['Confirmed', 'Completed'], 'RxPlacement' => ['Active', 'Completed']];

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private CandidateCompliance $compliance,
    ) {}

    /**
     * Work can later be cancelled, moved or deleted, but the law counts it once it has happened, so
     * the candidate keeps the date. A booking that never started holds nothing.
     */
    public function keepWorkDate(Entity $work): void
    {
        $candidateId = $work->getFetched('candidateId');
        $candidate = $candidateId ? $this->entityManager->getEntityById('RxCandidate', $candidateId) : null;

        if (!$candidate || !in_array($work->getFetched('status'), self::WORKED[$work->getEntityType()], true)) {
            return;
        }

        $today = $this->compliance->today();
        $date = min($this->lastDay($work) ?? $today, $today);

        if (!$this->hasStarted($work) || $date <= $candidate->get('workOnRecordUntil')) {
            return;
        }

        $candidate->set('workOnRecordUntil', $date);

        $this->entityManager->saveEntity($candidate, [SaveOption::SILENT => true]);
    }

    /**
     * A shift starts at a moment, so one booked for later today hasn't happened yet.
     */
    private function hasStarted(Entity $work): bool
    {
        if ($work->getEntityType() === 'RxPlacement') {
            return $work->getFetched('startDate') <= $this->compliance->today();
        }

        return $work->getFetched('dateStart') <= DateTime::getSystemNowString();
    }

    /**
     * @return ?string The last local date of the work as last saved.
     */
    private function lastDay(Entity $work): ?string
    {
        if ($work->getEntityType() === 'RxPlacement') {
            return $work->getFetched('endDate');
        }

        return $this->compliance->localDate($work->getFetched('dateEnd'));
    }

    /**
     * @throws Forbidden
     */
    public function assertCandidateRemovable(Entity $candidate): void
    {
        $credentials = $this->entityManager
            ->getRDBRepository('RxCredential')
            ->where(['candidateId' => $candidate->getId()])
            ->find();

        foreach ($credentials as $credential) {
            $this->assertCredentialRemovable($credential);
        }
    }

    /**
     * @throws Forbidden
     */
    public function assertCredentialRemovable(Entity $credential): void
    {
        $type = $credential->getFetched('type');
        $years = $this->metadata->get(['app', 'rxCompliance', 'retentionYearsAfterWork', $type ?? '']);

        if (!$years || !$this->workedSince($credential->getFetched('candidateId'), $this->yearsAgo($years))) {
            return;
        }

        $label = 'rxRetentionHold';

        throw Forbidden::createWithBody(
            $label,
            Body::create()->withMessageTranslation($label, 'RxCredential', ['type' => $type, 'years' => (string) $years])
        );
    }

    private function yearsAgo(int $years): string
    {
        return (new DateTimeImmutable($this->compliance->today()))->modify("-$years years")->format('Y-m-d');
    }

    private function workedSince(string $candidateId, string $date): bool
    {
        $candidate = $this->entityManager->getEntityById('RxCandidate', $candidateId);

        if ($candidate?->get('workOnRecordUntil') >= $date) {
            return true;
        }

        $shifts = $this->entityManager
            ->getRDBRepository('RxShift')
            ->where([
                'candidateId' => $candidateId,
                'status' => self::WORKED['RxShift'],
                'dateEnd>=' => $date,
            ])
            ->count();

        $placements = $this->entityManager
            ->getRDBRepository('RxPlacement')
            ->where([
                'candidateId' => $candidateId,
                'status' => self::WORKED['RxPlacement'],
                'OR' => [['endDate' => null], ['endDate>=' => $date]],
            ])
            ->count();

        return $shifts + $placements > 0;
    }
}
