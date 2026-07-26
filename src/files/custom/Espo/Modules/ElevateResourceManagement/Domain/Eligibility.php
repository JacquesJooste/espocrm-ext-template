<?php

namespace Espo\Modules\ElevateResourceManagement\Domain;

use Espo\ORM\Entity;

final class Eligibility
{
    /** @param array<int, mixed> $criteria */
    public static function matches(Entity $target, array $criteria): bool
    {
        foreach ($criteria as $item) {
            if (!is_array($item)) {
                return false;
            }

            $field = $item['field'] ?? null;
            $operator = $item['operator'] ?? null;
            $expected = $item['value'] ?? null;

            if (!is_string($field) || !is_string($operator)) {
                return false;
            }

            $actual = $target->get($field);
            $matches = match ($operator) {
                'equals' => $actual === $expected,
                'notEquals' => $actual !== $expected,
                'in' => is_array($expected) && in_array($actual, $expected, true),
                'isEmpty' => $actual === null || $actual === '' || $actual === [],
                'isNotEmpty' => !($actual === null || $actual === '' || $actual === []),
                default => false,
            };

            if (!$matches) {
                return false;
            }
        }

        return true;
    }
}
