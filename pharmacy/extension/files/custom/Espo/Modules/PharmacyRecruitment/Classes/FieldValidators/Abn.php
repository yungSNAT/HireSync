<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\FieldValidators;

use Espo\Core\FieldValidation\Validator;
use Espo\Core\FieldValidation\Validator\Data;
use Espo\Core\FieldValidation\Validator\Failure;
use Espo\Modules\PharmacyRecruitment\Classes\Identifier\Abn as AbnCheck;
use Espo\ORM\Entity;

/**
 * @implements Validator<Entity>
 */
class Abn implements Validator
{
    public function validate(Entity $entity, string $field, Data $data): ?Failure
    {
        $value = $entity->get($field);

        if (!$value || AbnCheck::isValid($value)) {
            return null;
        }

        return Failure::create();
    }
}
