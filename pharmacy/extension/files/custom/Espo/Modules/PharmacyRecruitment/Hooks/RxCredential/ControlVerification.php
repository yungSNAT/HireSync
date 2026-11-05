<?php

namespace Espo\Modules\PharmacyRecruitment\Hooks\RxCredential;

use DateTimeImmutable;
use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\CandidateCompliance;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\Evaluator;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Keeps `Verified` honest. Someone has to choose it, with the checks each type needs on the record,
 * and changing a checked fact drops the record back to `Pending` until someone chooses it again.
 */
class ControlVerification implements BeforeSave
{
    private const PENDING = 'Pending';

    private const RIGHT_TO_WORK = 'Right to work';
    private const CHILD_CHECK = 'Working with Children Check';
    private const INSURANCE = 'Professional indemnity insurance';

    private const TIME_LIMITED_VISA = 'Time-limited visa';

    private const VERIFIED_FACTS = [
        'candidateId', 'type', 'identifier', 'issueDate', 'expiryDate', 'evidenceId', 'verificationMethod',
        'verifyingEntity', 'conditions', 'workRightsBasis', 'registerStatus', 'checkType', 'dateOfBirth',
        'coverAmount', 'insuranceArrangement',
    ];

    public function __construct(
        private User $user,
        private Metadata $metadata,
        private CandidateCompliance $compliance,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->deriveExpiry($entity);

        if ($entity->isAttributeChanged('expiryDate')) {
            $entity->set('reminderLevel', null);
        }

        if ($entity->get('status') !== Evaluator::STATUS_VERIFIED) {
            $this->clearStamp($entity);

            return;
        }

        if ($entity->isNew() || $entity->isAttributeChanged('status')) {
            $this->assertComplete($entity);

            $entity->setMultiple([
                'verifiedById' => $this->user->getId(),
                'verifiedAt' => DateTime::getSystemNowString(),
            ]);

            return;
        }

        if ($this->isFactChanged($entity)) {
            $entity->set('status', self::PENDING);
            $this->clearStamp($entity);
        }
    }

    private function clearStamp(Entity $entity): void
    {
        $entity->setMultiple(['verifiedById' => null, 'verifiedAt' => null]);
    }

    /**
     * Some credentials carry no printed expiry: they count for a set time after they were issued.
     */
    private function deriveExpiry(Entity $entity): void
    {
        $months = $this->metadata->get(['app', 'rxCompliance', 'validityMonthsFromIssue', $entity->get('type') ?? '']);
        $issueDate = $entity->get('issueDate');

        if ($months && $issueDate) {
            $entity->set('expiryDate', (new DateTimeImmutable($issueDate))->modify("+$months months")->format('Y-m-d'));
        }
    }

    private function isFactChanged(Entity $entity): bool
    {
        foreach (self::VERIFIED_FACTS as $attribute) {
            if ($entity->isAttributeChanged($attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws Forbidden
     */
    private function assertComplete(Entity $entity): void
    {
        $noExpiryTypes = $this->metadata->get(['app', 'rxCompliance', 'noExpiryTypes']) ?? [];

        if (!$entity->get('verificationMethod')) {
            throw $this->refusal('rxVerificationNeedsMethod');
        }

        $this->assertIssued($entity);

        if (!$entity->get('expiryDate') && !in_array($entity->get('type'), $noExpiryTypes, true)) {
            throw $this->refusal('rxVerificationNeedsExpiry');
        }

        match ($entity->get('type')) {
            self::RIGHT_TO_WORK => $this->assertRightToWork($entity),
            self::CHILD_CHECK => $this->assertChildCheck($entity),
            self::INSURANCE => $this->assertInsurance($entity),
            default => null,
        };
    }

    /**
     * A date to come isn't issued yet, and a fixed life runs from the issue date, so a typed expiry
     * can't stretch it.
     *
     * @throws Forbidden
     */
    private function assertIssued(Entity $entity): void
    {
        $months = $this->metadata->get(['app', 'rxCompliance', 'validityMonthsFromIssue', $entity->get('type') ?? '']);
        $issueDate = $entity->get('issueDate');

        if (($months && !$issueDate) || $issueDate > $this->compliance->today()) {
            throw $this->refusal('rxVerificationNeedsIssueDate');
        }
    }

    /**
     * A blank expiry means open-ended, so it has to rest on a stated basis and not on an omission.
     *
     * @throws Forbidden
     */
    private function assertRightToWork(Entity $entity): void
    {
        $basis = $entity->get('workRightsBasis');

        if (!$basis || ($basis === self::TIME_LIMITED_VISA && !$entity->get('expiryDate'))) {
            throw $this->refusal('rxRightToWorkNeedsBasis');
        }
    }

    /**
     * Only a cleared paid check counts. OCG also allows work on an application in some settings,
     * but not at NSW Health, so that stays `Pending` until the business decides otherwise.
     *
     * @throws Forbidden
     */
    private function assertChildCheck(Entity $entity): void
    {
        if ($entity->get('registerStatus') !== 'Cleared'
            || $entity->get('checkType') !== 'Paid'
            || !$entity->get('dateOfBirth')
        ) {
            throw $this->refusal('rxChildCheckIncomplete');
        }
    }

    /**
     * @throws Forbidden
     */
    private function assertInsurance(Entity $entity): void
    {
        $minimum = $this->metadata->get(['app', 'rxCompliance', 'minimumInsuranceCover']);

        if ($entity->get('coverAmount') < $minimum) {
            throw $this->refusal('rxInsuranceBelowMinimum');
        }
    }

    private function refusal(string $label): Forbidden
    {
        return Forbidden::createWithBody($label, Body::create()->withMessageTranslation($label, 'RxCredential'));
    }
}
