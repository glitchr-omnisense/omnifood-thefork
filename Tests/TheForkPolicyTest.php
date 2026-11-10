<?php

namespace Omnifood\TheFork\Tests;

use Omnifood\Exception\RateLimitedException;
use Omnifood\Exception\UsageLimitReachedException;
use Omnifood\Model\Guest;
use Omnifood\Model\Reservation;
use Omnifood\TheFork\TheForkPlatform;
use Omnifood\TheFork\TheForkPlatformFactory;
use Omnifood\Registry;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * TheFork's usage policy, declared as data (contract-only, the partners' terms' 200 a minute and 10,000 a day), and a 429 to
 * a reservation's creation: sent once, surfaced, the next calls held unsent for the wait.
 */
final class TheForkPolicyTest extends TestCase
{
    /** @var list<array{string, string}> method and URL of each call but the token's */
    private array $calls = [];

    /** @var list<MockResponse> */
    private array $answers = [];

    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared, 'composer.json\'s extra.omnisense.policy names it');
        self::assertSame(['omnifood/thefork', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0], 'it reads as the schema says');
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('thefork', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame('contract', $policy->rights->commercial->value, 'contract-only');
        self::assertSame([200, 10000], array_map(static fn ($limit) => $limit->max, $policy->limitsFor('createReservation')));
        self::assertSame('forbidden', $policy->rights->redistribution->value, 'no data forwarding');
        $registry = new Registry([new TheForkPlatformFactory($this->http())], ['thefork' => ['factory' => 'thefork', 'options' => ['client_id' => 'cid', 'client_secret' => 'secret', 'restaurant_id' => 'r-1']]]);
        self::assertEquals($policy, $registry->policy('thefork'));
        self::assertSame(200, $registry->guard('thefork')->remaining('createReservation'));
    }

    public function testA429ToAWriteIsSurfacedNeverRetriedAndHoldsTheNextCalls(): void
    {
        $this->answers = [new MockResponse('{"message": "Too Many Requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']])];
        $platform = (new TheForkPlatformFactory($this->http()))->create(['client_id' => 'cid', 'client_secret' => 'secret', 'restaurant_id' => 'r-1']);
        self::assertInstanceOf(TheForkPlatform::class, $platform);

        try {
            $platform->createReservation(new Reservation(new \DateTimeImmutable('2026-10-12T19:30:00+02:00'), 2, new Guest('Jo', 'Martin')));
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame(60, $e->retryAfter);
            self::assertSame(429, $e->status);
            self::assertSame('thefork', $e->platform);
        }
        self::assertCount(1, $this->calls, 'sent once, not retried');

        $this->expectException(UsageLimitReachedException::class);
        try {
            $platform->createReservation(new Reservation(new \DateTimeImmutable('2026-10-12T19:30:00+02:00'), 2, new Guest('Jo', 'Martin')));
        } finally {
            self::assertCount(1, $this->calls, 'held, not sent');
        }
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/oauth/token')) {
                return new MockResponse('{"access_token": "recorded", "expires_in": 86400, "token_type": "Bearer"}');
            }
            $this->calls[] = [$method, $url];

            return array_shift($this->answers) ?? new MockResponse('{}');
        });
    }
}
