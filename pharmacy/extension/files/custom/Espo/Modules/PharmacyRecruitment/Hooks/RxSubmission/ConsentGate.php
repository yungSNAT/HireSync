<?php

namespace Espo\Modules\PharmacyRecruitment\Hooks\RxSubmission;

use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Sending a résumé to a client discloses personal information, so a recorded consent date has to
 * exist before a submission can leave the agency.
 */
class ConsentGate implements BeforeSave
{
    private const STAGES_BEFORE_DISCLOSURE = ['Shortlisted', 'Rejected', 'Withdrawn'];

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (in_array($entity->get('stage'), self::STAGES_BEFORE_DISCLOSURE, true)) {
            return;
        }

        if (!$entity->get('consentDate')) {
            throw Forbidden::createWithBody(
                'rxConsentNeeded',
                Body::create()->withMessageTranslation('rxConsentNeeded', 'RxSubmission')
            );
        }
    }
}
