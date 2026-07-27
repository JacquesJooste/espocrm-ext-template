<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\ElevateRmInstance;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

final class ValidateConfiguration implements BeforeSave
{
    private const OPERATORS = ['equals', 'notEquals', 'in', 'isEmpty', 'isNotEmpty'];
    private const SCALAR_TYPES = ['varchar', 'text', 'enum', 'multiEnum', 'int', 'float', 'autoincrement', 'bool', 'date', 'datetime', 'datetimeOptional'];

    public function __construct(private Metadata $metadata) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $targetType = (string) $entity->get('targetEntityType');

        if ($targetType === '' || str_starts_with($targetType, 'ElevateRm') || $targetType === 'ElevateResourceManagement') {
            throw new BadRequest('A non-extension target entity is required.');
        }

        if (!$this->metadata->get("scopes.$targetType.entity") || !$this->metadata->get("scopes.$targetType.object")) {
            throw new BadRequest('The target must be an object entity.');
        }

        foreach (['identifierField', 'nameField'] as $mapping) {
            $this->assertFieldType($targetType, (string) $entity->get($mapping), self::SCALAR_TYPES, $mapping);
        }

        $statusField = (string) $entity->get('statusField');
        $this->assertFieldType($targetType, $statusField, ['enum', 'varchar'], 'statusField');
        $this->assertStatusValues($targetType, $statusField, array_filter([
            $entity->get('inProgressStatus'),
            ...((array) ($entity->get('completedStatusList') ?? [])),
            $entity->get('addTimeLogsTargetStatus'),
            $entity->get('readyForBillingTargetStatus'),
            $entity->get('invoicedTargetStatus'),
        ], fn (mixed $value): bool => is_string($value) && $value !== ''));

        $this->assertLink($targetType, (string) $entity->get('resourceField'), 'User', ['link', 'linkMultiple'], 'resourceField');

        if ($entity->get('accountField')) {
            $this->assertLink($targetType, (string) $entity->get('accountField'), 'Account', ['link'], 'accountField');
        }
        if ($entity->get('contactField')) {
            $this->assertLink($targetType, (string) $entity->get('contactField'), 'Contact', ['link'], 'contactField');
        }

        $criteria = (array) ($entity->get('eligibilityCriteria') ?? []);
        if (count($criteria) > 10) {
            throw new BadRequest('At most ten eligibility rules are allowed.');
        }

        foreach ($criteria as $rule) {
            if (!is_array($rule) || !is_string($rule['field'] ?? null) || !is_string($rule['operator'] ?? null)) {
                throw new BadRequest('Every eligibility rule requires a field and operator.');
            }
            if (!in_array($rule['operator'], self::OPERATORS, true)) {
                throw new BadRequest('Unsupported eligibility operator.');
            }
            $this->assertFieldType($targetType, $rule['field'], self::SCALAR_TYPES, 'eligibilityCriteria');
            if ($rule['operator'] === 'in' && !is_array($rule['value'] ?? null)) {
                throw new BadRequest('The in operator requires a value list.');
            }
        }
    }

    /** @param string[] $allowed */
    private function assertFieldType(string $entityType, string $field, array $allowed, string $mapping): void
    {
        $type = $this->metadata->get("entityDefs.$entityType.fields.$field.type");
        if (!is_string($type) || !in_array($type, $allowed, true)) {
            throw new BadRequest("Invalid field selected for $mapping.");
        }
    }

    /** @param string[] $types */
    private function assertLink(string $entityType, string $field, string $target, array $types, string $mapping): void
    {
        $this->assertFieldType($entityType, $field, $types, $mapping);
        $linkName = $this->metadata->get("entityDefs.$entityType.fields.$field.link");
        $linkName = is_string($linkName) && $linkName !== '' ? $linkName : $field;
        $fieldTarget = $this->metadata->get("entityDefs.$entityType.fields.$field.entity");
        $linkTarget = is_string($fieldTarget) && $fieldTarget !== ''
            ? $fieldTarget
            : $this->metadata->get("entityDefs.$entityType.links.$linkName.entity");

        if ($linkTarget !== $target) {
            throw new BadRequest("The $mapping mapping must link to $target.");
        }
    }

    /** @param mixed[] $values */
    private function assertStatusValues(string $entityType, string $field, array $values): void
    {
        $options = $this->metadata->get("entityDefs.$entityType.fields.$field.options");
        if (!is_array($options)) {
            return;
        }
        foreach ($values as $value) {
            if (!in_array($value, $options, true)) {
                throw new BadRequest("Status value '$value' does not exist on the target.");
            }
        }
    }
}
