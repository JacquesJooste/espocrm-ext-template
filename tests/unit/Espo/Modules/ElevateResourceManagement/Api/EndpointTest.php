<?php

namespace Espo\Core\Api {
    interface Action {}
}

namespace tests\unit\Espo\Modules\ElevateResourceManagement\Api {
    use Espo\Modules\ElevateResourceManagement\Api\Endpoint;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionMethod;

    final class EndpointTest extends TestCase
    {
        public function testParsedBodyObjectsAreNormalizedRecursively(): void
        {
            $endpoint = (new ReflectionClass(Endpoint::class))->newInstanceWithoutConstructor();
            $method = new ReflectionMethod(Endpoint::class, 'normalizeObject');

            $body = (object) [
                'name' => 'Implementation',
                'items' => [
                    (object) [
                        'sequence' => 0,
                        'create' => (object) [
                            'name' => 'New Work Item',
                            'defaultEstimateSeconds' => 3600,
                        ],
                    ],
                ],
            ];

            self::assertSame([
                'name' => 'Implementation',
                'items' => [
                    [
                        'sequence' => 0,
                        'create' => [
                            'name' => 'New Work Item',
                            'defaultEstimateSeconds' => 3600,
                        ],
                    ],
                ],
            ], $method->invoke($endpoint, $body));
        }
    }
}
