<?php

namespace tests\unit\Espo\Modules\ElevateResourceManagement\Domain;

use Espo\Modules\ElevateResourceManagement\Domain\LegacyMigration;
use PHPUnit\Framework\TestCase;

final class LegacyMigrationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 5) . '/fixtures/legacy-upgrade.json';
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $this->fixture = $decoded;
    }

    public function testLegacyEstimateAndDescriptionArePreservedExactly(): void
    {
        $legacy = $this->fixture['workBlock'];
        $values = LegacyMigration::workItem(
            $legacy['id'],
            $legacy['name'],
            $legacy['activities'],
            $legacy['estimatedSeconds'],
            $legacy['active']
        );

        self::assertSame(3770, $values['defaultEstimateSeconds']);
        self::assertSame(
            "Remove failed device\nConfigure and validate replacement",
            $values['description']
        );
        self::assertSame('legacy-template-001', $values['legacyWorkBlockId']);
        self::assertSame($values, LegacyMigration::workItem(
            $legacy['id'],
            $legacy['name'],
            $legacy['activities'],
            $legacy['estimatedSeconds'],
            $legacy['active']
        ));
    }

    public function testScheduleMarkerAndAttendingUsersAreRepeatable(): void
    {
        $schedule = $this->fixture['scheduledBlock'];
        $run = LegacyMigration::workBlockRun(
            $schedule['id'],
            $schedule['name'],
            $schedule['status'],
            $schedule['milestoneKind'],
            $schedule['sequence'],
            $schedule['estimatedSeconds']
        );
        $links = LegacyMigration::timeEntryLinks(
            'new-run-id',
            'new-item-run-id',
            $this->fixture['timeEntry']['attendeeIds']
        );

        self::assertSame('legacy-schedule-001', $run['legacyScheduledBlockId']);
        self::assertSame(3770, $run['totalEstimateSeconds']);
        self::assertSame(['user-a', 'user-b'], $links['usersIds']);
        self::assertSame(3600, $this->fixture['timeEntry']['labourSeconds']);
    }

    public function testBillingPayloadAndChecksumRemainUntouched(): void
    {
        $snapshot = $this->fixture['billingSnapshot'];

        self::assertSame(
            $snapshot['checksum'],
            hash('sha256', $snapshot['json'])
        );
    }
}
