<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Compliance;

/**
 * Decides whether a candidate's credentials satisfy a list of required types on a date.
 *
 * A required type is satisfied only by a verified credential that is current on the date.
 * A credential is current through its expiry date. Dates are local YYYY-MM-DD strings, so
 * they compare as text.
 */
final class Evaluator
{
    public const STATUS_VERIFIED = 'Verified';

    /**
     * @param string[] $required
     * @param CredentialRecord[] $credentials
     * @param string[] $noExpiryTypes Types where a blank expiry means open-ended. A recorded
     *     expiry still counts: a citizen's right to work is open-ended, a visa's is not.
     */
    public function evaluate(array $required, array $credentials, string $onDate, array $noExpiryTypes): Result
    {
        $missing = [];
        $expired = [];
        $unverified = [];
        $expiries = [];

        foreach (array_unique($required) as $type) {
            $held = array_filter($credentials, fn (CredentialRecord $item) => $item->type === $type);

            if ($held === []) {
                $missing[] = $type;

                continue;
            }

            $verified = array_filter($held, fn (CredentialRecord $item) => $item->status === self::STATUS_VERIFIED);

            $dates = array_filter(array_map(fn (CredentialRecord $item) => $item->expiryDate, $verified));

            if (in_array($type, $noExpiryTypes, true) && count($dates) < count($verified)) {
                continue;
            }

            if ($dates === []) {
                $unverified[] = $type;

                continue;
            }

            $latest = max($dates);

            if ($latest < $onDate) {
                $expired[] = $type;

                continue;
            }

            $expiries[] = $latest;
        }

        return new Result($missing, $expired, $unverified, $expiries === [] ? null : min($expiries));
    }
}
