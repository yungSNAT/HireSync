<?php

namespace Espo\Modules\PharmacyRecruitment\Hooks\Account;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Fills in what a client type usually needs. These land on the client's own editable list, because
 * the rules have exceptions a manager must be able to record for a site.
 */
class DefaultRequirements implements BeforeSave
{
    public function __construct(private Metadata $metadata) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isAttributeChanged('rxClientType')) {
            return;
        }

        $defaults = $this->metadata->get(
            ['app', 'rxCompliance', 'clientTypeRequirements', $entity->get('rxClientType') ?? '']
        );

        if ($defaults) {
            $current = $entity->get('rxRequiredCredentials') ?? [];

            $entity->set('rxRequiredCredentials', array_values(array_unique([...$current, ...$defaults])));
        }
    }
}
