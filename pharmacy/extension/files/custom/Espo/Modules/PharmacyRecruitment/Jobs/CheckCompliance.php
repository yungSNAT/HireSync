<?php

namespace Espo\Modules\PharmacyRecruitment\Jobs;

use Espo\Core\AclManager;
use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\CandidateCompliance;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\PlacementGate;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Daily: refreshes every candidate's standing, raises one task per expiry threshold, and flags
 * confirmed shifts whose candidate is no longer clear.
 */
class CheckCompliance implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private DateTime $dateTime,
        private CandidateCompliance $compliance,
        private PlacementGate $gate,
        private AclManager $aclManager,
    ) {}

    public function run(): void
    {
        $this->refreshCandidates();
        $this->raiseExpiryReminders();
        $this->flagShiftsAtRisk();
    }

    private function refreshCandidates(): void
    {
        foreach ($this->entityManager->getRDBRepository('RxCandidate')->find() as $candidate) {
            $this->compliance->refresh($candidate);
        }
    }

    private function raiseExpiryReminders(): void
    {
        /** @var int[] $thresholds */
        $thresholds = $this->metadata->get(['app', 'rxCompliance', 'reminderDays']);
        rsort($thresholds);

        $credentials = $this->entityManager
            ->getRDBRepository('RxCredential')
            ->where([
                'status' => 'Verified',
                'expiryDate!=' => null,
                'expiryDate<=' => $this->compliance->daysFromToday($thresholds[0]),
            ])
            ->find();

        foreach ($credentials as $credential) {
            $this->remind($credential, $thresholds);
        }
    }

    /**
     * @param int[] $thresholds Descending.
     */
    private function remind(Entity $credential, array $thresholds): void
    {
        $level = null;

        foreach ($thresholds as $days) {
            if ($credential->get('expiryDate') <= $this->compliance->daysFromToday($days)) {
                $level = $days;
            }
        }

        $last = $credential->get('reminderLevel');

        if ($level === null || ($last !== null && $last <= $level)) {
            return;
        }

        $candidate = $this->candidate($credential);
        $userId = $this->reminderUser($credential);

        // With nobody to tell, the level stays put so that a later run can still raise the reminder.
        if (!$candidate || !$userId) {
            return;
        }

        $this->createTask(
            $candidate,
            sprintf(
                'Credential expires %s: %s',
                $this->dateTime->convertSystemDate($credential->get('expiryDate')),
                $credential->get('type'),
            ),
            $userId,
        );

        $credential->set('reminderLevel', $level);

        $this->entityManager->saveEntity($credential, [SaveOption::SILENT => true]);
    }

    /**
     * Not the candidate's recruiter: the task names the type, which can be health information.
     */
    private function reminderUser(Entity $credential): ?string
    {
        foreach ([$credential->get('assignedUserId'), $credential->get('verifiedById')] as $userId) {
            $user = $userId ? $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId) : null;

            if ($user instanceof User && $user->isActive() && $this->aclManager->checkEntityRead($user, $credential)) {
                return $userId;
            }
        }

        return null;
    }

    private function flagShiftsAtRisk(): void
    {
        $lookahead = $this->metadata->get(['app', 'rxCompliance', 'shiftLookaheadDays']);

        $shifts = $this->entityManager
            ->getRDBRepository('RxShift')
            ->where([
                'status' => 'Confirmed',
                'dateEnd>=' => $this->compliance->dayStartUtc(),
                'dateStart<' => $this->compliance->dayStartUtc($lookahead + 1),
            ])
            ->find();

        foreach ($shifts as $shift) {
            $this->assessShift($shift);
        }
    }

    private function assessShift(Entity $shift): void
    {
        $result = $this->compliance->check(
            $shift->get('candidateId'),
            $this->gate->shiftRequirements($shift),
            $this->compliance->localDate($shift->get('dateEnd')),
        );

        $atRisk = !$result->isClear();

        if ($atRisk === (bool) $shift->get('atRisk')) {
            return;
        }

        $shift->set('atRisk', $atRisk);

        $this->entityManager->saveEntity($shift, [SaveOption::SILENT => true]);

        if ($atRisk) {
            $candidate = $this->candidate($shift);

            if ($candidate) {
                $this->createTask(
                    $candidate,
                    'Confirmed shift at risk: ' . $shift->get('name'),
                    $shift->get('assignedUserId') ?? $candidate->get('assignedUserId'),
                );
            }
        }
    }

    private function candidate(Entity $record): ?Entity
    {
        $id = $record->get('candidateId');

        return $id ? $this->entityManager->getEntityById('RxCandidate', $id) : null;
    }

    private function createTask(Entity $candidate, string $name, ?string $assignedUserId): void
    {
        $this->entityManager->createEntity('Task', [
            'name' => $name,
            'status' => 'Not Started',
            'priority' => 'High',
            'parentType' => 'RxCandidate',
            'parentId' => $candidate->getId(),
            'assignedUserId' => $assignedUserId,
        ]);
    }
}
