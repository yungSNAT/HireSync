<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Compliance;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Applies the requirement rules in `app > rxCompliance` to a candidate's stored credentials.
 */
class CandidateCompliance
{
    public const STATUS_NOT_ASSESSED = 'Not assessed';
    public const STATUS_CLEAR = 'Clear';
    public const STATUS_EXPIRING = 'Expiring';
    public const STATUS_BLOCKED = 'Blocked';

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Config $config,
        private Evaluator $evaluator,
    ) {}

    /**
     * @return string[]
     */
    public function roleRequirements(?string $profession): array
    {
        return $this->metadata->get(['app', 'rxCompliance', 'roleRequirements', $profession ?? '']) ?? [];
    }

    /**
     * The types the role rules use. Anyone can see these named; others may be health information.
     *
     * @return string[]
     */
    public function roleTypes(): array
    {
        $roles = $this->metadata->get(['app', 'rxCompliance', 'roleRequirements']) ?? [];

        return array_merge(...array_values($roles));
    }

    /**
     * @param string[] $required
     */
    public function check(?string $candidateId, array $required, string $onDate): Result
    {
        return $this->evaluator->evaluate(
            $required,
            $candidateId ? $this->loadCredentials($candidateId) : [],
            $onDate,
            $this->metadata->get(['app', 'rxCompliance', 'noExpiryTypes']) ?? [],
        );
    }

    /**
     * Sets the candidate's standing against their own role's requirements, without saving. Only
     * these base requirements reach the summary, which recruiters can read: site-specific types
     * may be health information.
     */
    public function apply(Entity $candidate): void
    {
        $today = $this->today();
        $required = $this->roleRequirements($candidate->get('profession'));
        $result = $this->check($candidate->getId(), $required, $today);

        $candidate->setMultiple([
            'complianceStatus' => $required === [] ? self::STATUS_NOT_ASSESSED : $this->status($result),
            'complianceSummary' => $this->summary($result, $this->hasConditions($candidate)),
            'nextCredentialExpiry' => $result->nextExpiry,
        ]);
    }

    public function refresh(Entity $candidate): void
    {
        $this->apply($candidate);

        if ($candidate->isAttributeChanged('complianceStatus')
            || $candidate->isAttributeChanged('complianceSummary')
            || $candidate->isAttributeChanged('nextCredentialExpiry')
        ) {
            $this->entityManager->saveEntity($candidate, [SaveOption::SILENT => true]);
        }
    }

    public function today(): string
    {
        return (new DateTimeImmutable('now', $this->timeZone()))->format('Y-m-d');
    }

    public function daysFromToday(int $days): string
    {
        return (new DateTimeImmutable('now', $this->timeZone()))
            ->add(new DateInterval("P{$days}D"))
            ->format('Y-m-d');
    }

    /**
     * The last date that counts as expiring soon.
     */
    public function expiringThrough(): string
    {
        return $this->daysFromToday($this->metadata->get(['app', 'rxCompliance', 'expiringWindowDays']));
    }

    /**
     * The stored UTC date-time at which a local day begins. Date-times are stored in UTC, so a
     * Sydney morning falls on the previous UTC date and a plain date comparison would miss it.
     */
    public function dayStartUtc(int $days = 0): string
    {
        return (new DateTimeImmutable('today', $this->timeZone()))
            ->add(new DateInterval("P{$days}D"))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTime::SYSTEM_DATE_TIME_FORMAT);
    }

    /**
     * @param string $dateTime Stored UTC date-time.
     */
    public function localDate(string $dateTime): string
    {
        return (new DateTimeImmutable($dateTime, new DateTimeZone('UTC')))
            ->setTimezone($this->timeZone())
            ->format('Y-m-d');
    }

    private function timeZone(): DateTimeZone
    {
        return new DateTimeZone($this->config->get('timeZone') ?: 'UTC');
    }

    private function status(Result $result): string
    {
        if (!$result->isClear()) {
            return self::STATUS_BLOCKED;
        }

        if ($result->nextExpiry !== null && $result->nextExpiry <= $this->expiringThrough()) {
            return self::STATUS_EXPIRING;
        }

        return self::STATUS_CLEAR;
    }

    /**
     * Recruiters can't read credentials, so register conditions reach them only as this prompt.
     */
    private function hasConditions(Entity $candidate): bool
    {
        $count = $this->entityManager
            ->getRDBRepository('RxCredential')
            ->where([
                'candidateId' => $candidate->getId(),
                'status' => Evaluator::STATUS_VERIFIED,
                'conditions!=' => '',
            ])
            ->count();

        return $count > 0;
    }

    private function summary(Result $result, bool $hasConditions): ?string
    {
        $parts = [];

        foreach (['Missing' => $result->missing, 'Expired' => $result->expired, 'Unverified' => $result->unverified] as $label => $types) {
            if ($types !== []) {
                $parts[] = $label . ': ' . implode(', ', $types);
            }
        }

        if ($hasConditions) {
            $parts[] = 'Conditions on record: check with compliance before confirming';
        }

        return $parts === [] ? null : implode('. ', $parts);
    }

    /**
     * @return CredentialRecord[]
     */
    private function loadCredentials(string $candidateId): array
    {
        $credentials = $this->entityManager
            ->getRDBRepository('RxCredential')
            ->where(['candidateId' => $candidateId])
            ->find();

        $records = [];

        foreach ($credentials as $credential) {
            $records[] = new CredentialRecord(
                $credential->get('type'),
                $credential->get('status'),
                $credential->get('expiryDate'),
            );
        }

        return $records;
    }
}
