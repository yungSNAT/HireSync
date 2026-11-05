<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Compliance;

use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Refuses to confirm a shift or start a placement unless the candidate holds every required
 * credential. It runs in repository hooks, so it covers the API, mass update and import alike.
 * The relationship routes write without a save, which is why the links to this work are read-only.
 */
class PlacementGate
{
    /** Site types may be health information, and anyone who can edit the work reads this message. */
    private const WITHHELD = 'a client or job requirement (ask compliance)';

    public function __construct(
        private EntityManager $entityManager,
        private CandidateCompliance $compliance,
    ) {}

    /**
     * @throws Forbidden
     */
    public function assertShiftCanConfirm(Entity $shift): void
    {
        $candidateId = $shift->get('candidateId');

        if (!$candidateId) {
            throw $this->refusal('rxShiftNeedsCandidate');
        }

        $this->assertRoleHasRules($shift->get('profession'));
        $this->assertPartiesHaveRules($shift);
        $this->assertLinksHold($shift);

        $onDate = $this->compliance->localDate($shift->get('dateEnd'));

        $this->assertClear($candidateId, $this->shiftRequirements($shift), $onDate);
        $this->assertNotDoubleBooked($shift);
    }

    /**
     * Checks the first day only. A fixed term can outlast a registration or insurance year, so the
     * expiry reminders cover the rest of it.
     *
     * @throws Forbidden
     */
    public function assertPlacementCanStart(Entity $placement): void
    {
        $candidateId = $placement->get('candidateId');
        $startDate = $placement->get('startDate');
        $job = $this->find('RxJobOrder', $placement->get('jobOrderId'));

        if (!$candidateId || !$startDate || !$job) {
            throw $this->refusal('rxPlacementIncomplete');
        }

        $this->assertPartiesHaveRules($placement);
        $this->assertLinksHold($placement);

        $this->assertClear($candidateId, $this->workRequirements($placement), $startDate);
    }

    /**
     * A shift can leave `Confirmed` no longer clear, so the daily check needs this list too.
     *
     * @return string[]
     */
    public function shiftRequirements(Entity $shift): array
    {
        return [
            ...$this->compliance->roleRequirements($shift->get('profession')),
            ...$this->workRequirements($shift),
        ];
    }

    /**
     * The job's role and the candidate's own role count, so a wrong role on the work can't lower
     * the bar.
     *
     * @return string[]
     */
    private function workRequirements(Entity $work): array
    {
        $job = $this->find('RxJobOrder', $work->get('jobOrderId'));
        $candidate = $this->find('RxCandidate', $work->get('candidateId'));
        $account = $this->find('Account', $work->get('accountId'));

        return [
            ...$this->compliance->roleRequirements($job?->get('profession')),
            ...$this->compliance->roleRequirements($candidate?->get('profession')),
            ...$job?->get('requiredCredentials') ?? [],
            ...$account?->get('rxRequiredCredentials') ?? [],
        ];
    }

    private function find(string $entityType, ?string $id): ?Entity
    {
        return $id ? $this->entityManager->getEntityById($entityType, $id) : null;
    }

    /**
     * A role added to the enum without rules must not pass as having nothing to check.
     *
     * @throws Forbidden
     */
    private function assertRoleHasRules(?string $profession): void
    {
        if ($this->compliance->roleRequirements($profession) === []) {
            throw $this->refusal('rxRoleHasNoRules', ['role' => $profession ?? '']);
        }
    }

    /**
     * @throws Forbidden
     */
    private function assertPartiesHaveRules(Entity $work): void
    {
        $job = $this->find('RxJobOrder', $work->get('jobOrderId'));
        $candidate = $this->find('RxCandidate', $work->get('candidateId'));

        foreach ([$job, $candidate] as $party) {
            if ($party) {
                $this->assertRoleHasRules($party->get('profession'));
            }
        }
    }

    /**
     * A deleted client or job would otherwise drop its requirements in silence, and work for
     * another client would skip the ones the job's client set.
     *
     * @throws Forbidden
     */
    private function assertLinksHold(Entity $work): void
    {
        $jobId = $work->get('jobOrderId');
        $accountId = $work->get('accountId');
        $job = $this->find('RxJobOrder', $jobId);

        if (!$this->find('Account', $accountId) || ($jobId && !$job)) {
            throw $this->refusal('rxLinkedRecordDeleted');
        }

        if ($job && $job->get('accountId') !== $accountId) {
            throw $this->refusal('rxClientNotJobClient');
        }
    }

    /**
     * @param string[] $required
     * @throws Forbidden
     */
    private function assertClear(string $candidateId, array $required, string $onDate): void
    {
        $result = $this->compliance->check($candidateId, $required, $onDate);

        if ($result->isClear()) {
            return;
        }

        $items = [...$result->missing, ...$result->expired, ...$result->unverified];
        $named = array_intersect($items, $this->compliance->roleTypes());

        if (count($named) < count($items)) {
            $named[] = self::WITHHELD;
        }

        throw $this->refusal('rxNotCleared', ['items' => implode(', ', $named)]);
    }

    /**
     * shortcut: this reads then writes without a lock, so two recruiters confirming the same
     * person in the same moment could both pass. A row lock on the candidate would close it.
     *
     * @throws Forbidden
     */
    private function assertNotDoubleBooked(Entity $shift): void
    {
        $clash = $this->entityManager
            ->getRDBRepository('RxShift')
            ->where([
                'id!=' => $shift->getId(),
                'candidateId' => $shift->get('candidateId'),
                'status' => ['Confirmed', 'Completed'],
                'dateStart<' => $shift->get('dateEnd'),
                'dateEnd>' => $shift->get('dateStart'),
            ])
            ->findOne();

        if ($clash) {
            throw $this->refusal('rxDoubleBooked', ['shift' => $clash->get('name') ?? '']);
        }
    }

    /**
     * @param array<string, string> $data
     */
    private function refusal(string $label, array $data = []): Forbidden
    {
        return Forbidden::createWithBody(
            $label,
            Body::create()->withMessageTranslation($label, 'RxShift', $data)
        );
    }
}
