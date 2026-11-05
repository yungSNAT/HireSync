<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Compliance;

/**
 * The outcome of checking required credential types on one date.
 */
final readonly class Result
{
    /**
     * @param string[] $missing Required types with no credential at all.
     * @param string[] $expired Required types whose verified credentials have all lapsed.
     * @param string[] $unverified Required types held but not verified.
     * @param ?string $nextExpiry The first date on which a satisfied type lapses.
     */
    public function __construct(
        public array $missing,
        public array $expired,
        public array $unverified,
        public ?string $nextExpiry,
    ) {}

    public function isClear(): bool
    {
        return $this->missing === [] && $this->expired === [] && $this->unverified === [];
    }
}
