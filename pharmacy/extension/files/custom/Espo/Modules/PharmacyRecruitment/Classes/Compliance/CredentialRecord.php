<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Compliance;

/**
 * The part of a credential that decides whether it can be relied on.
 */
final readonly class CredentialRecord
{
    /**
     * @param ?string $expiryDate YYYY-MM-DD.
     */
    public function __construct(
        public string $type,
        public string $status,
        public ?string $expiryDate,
    ) {}
}
