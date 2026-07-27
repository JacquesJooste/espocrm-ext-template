<?php

namespace tests\integration\Espo\Modules\ElevateResourceManagement;

use PHPUnit\Framework\TestCase;

final class ProductionContractTest extends TestCase
{
    private string $baseUrl;
    private string $apiKey;
    private string $userId;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim((string) getenv('ESPO_API_BASE_URL'), '/');
        $this->apiKey = (string) getenv('ESPO_API_KEY');
        $this->userId = (string) getenv('ESPO_TEST_USER_ID');

        if ($this->baseUrl === '' || $this->apiKey === '' || $this->userId === '') {
            self::markTestSkipped(
                'Set ESPO_API_BASE_URL, ESPO_API_KEY and ESPO_TEST_USER_ID for integration tests.'
            );
        }
    }

    public function testMeetingCreationAndBusyRangesWithScheduledBlocksEnabled(): void
    {
        $start = gmdate('Y-m-d H:i:s', time() + 3600);
        $end = gmdate('Y-m-d H:i:s', time() + 5400);
        $created = $this->request('POST', '/Meeting', [
            'name' => 'Elevate RM calendar contract ' . bin2hex(random_bytes(4)),
            'status' => 'Planned',
            'dateStart' => $start,
            'dateEnd' => $end,
            'assignedUserId' => $this->userId,
        ]);

        self::assertContains($created['status'], [200, 201]);
        self::assertIsArray($created['json']);
        $meetingId = $created['json']['id'] ?? null;
        self::assertIsString($meetingId);

        try {
            $busy = $this->request('GET', '/Timeline/busyRanges?' . http_build_query([
                'from' => gmdate('Y-m-d H:i:s', time()),
                'to' => gmdate('Y-m-d H:i:s', time() + 86400),
                'userIdList' => $this->userId,
            ]));
            self::assertSame(200, $busy['status'], $busy['body']);
            self::assertArrayHasKey($this->userId, $busy['json']);
        } finally {
            $this->request('DELETE', '/Meeting/' . rawurlencode($meetingId));
        }
    }

    public function testSettingsRouteReturnsSingletonAndSecondRecordIsRejected(): void
    {
        $settings = $this->request('GET', '/ElevateResourceManagement/settings');
        self::assertSame(200, $settings['status'], $settings['body']);
        self::assertSame('Elevate Resource Management', $settings['json']['name'] ?? null);

        $duplicate = $this->request('POST', '/ElevateRmSettings', [
            'name' => 'Duplicate',
            'operationsManagerId' => $settings['json']['operationsManagerId'],
            'billingAdministratorId' => $settings['json']['billingAdministratorId'],
        ]);
        self::assertContains($duplicate['status'], [403, 409], $duplicate['body']);
    }

    public function testRoleAwareNavigationContractIsAvailable(): void
    {
        $response = $this->request('GET', '/ElevateResourceManagement/permissions');
        self::assertSame(200, $response['status'], $response['body']);
        self::assertArrayHasKey('manager', $response['json']);
        self::assertArrayHasKey('billingManager', $response['json']);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int,body:string,json:array<string,mixed>}
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $headers = [
            'X-Api-Key: ' . $this->apiKey,
            'Accept: application/json',
        ];
        $content = null;
        if ($payload !== null) {
            $content = json_encode($payload, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $body = file_get_contents($this->baseUrl . $path, false, $context);
        self::assertNotFalse($body, "No response received for $method $path.");
        $responseHeaders = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $matches);
        $decoded = json_decode($body, true);

        return [
            'status' => (int) ($matches[1] ?? 0),
            'body' => $body,
            'json' => is_array($decoded) ? $decoded : [],
        ];
    }
}
