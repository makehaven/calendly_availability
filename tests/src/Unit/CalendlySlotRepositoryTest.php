<?php

namespace Drupal\Tests\calendly_availability\Unit;

use Drupal\calendly_availability\Service\CalendlyAlertService;
use Drupal\calendly_availability\Service\CalendlySlotRepository;
use Drupal\calendly_availability\Service\CalendlyTokenManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

/**
 * Tests the slot repository's fetch, caching, and stale-fallback behavior.
 *
 * @coversDefaultClass \Drupal\calendly_availability\Service\CalendlySlotRepository
 * @group calendly_availability
 */
class CalendlySlotRepositoryTest extends UnitTestCase {

  /**
   * Queued mock responses consumed by the Guzzle client, in request order.
   */
  protected MockHandler $mockHandler;

  /**
   * Shared cache backend so cached data persists across repository calls.
   */
  protected MemoryBackend $cache;

  /**
   * Fixed "now" returned by the mocked time service.
   */
  protected int $now = 1750000000;

  /**
   * Builds a repository wired to a mock HTTP client and in-memory cache.
   */
  protected function buildRepository(?string $token = 'test-token'): CalendlySlotRepository {
    $this->mockHandler = new MockHandler();
    $client = new Client(['handler' => HandlerStack::create($this->mockHandler)]);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn () => $this->now);
    $time->method('getCurrentTime')->willReturnCallback(fn () => $this->now);

    if (!isset($this->cache)) {
      $this->cache = new MemoryBackend($time);
    }

    $token_manager = $this->createMock(CalendlyTokenManager::class);
    $token_manager->method('getValidAccessToken')->willReturn($token);

    $alerts = $this->createMock(CalendlyAlertService::class);

    return new CalendlySlotRepository(
      $client,
      new NullLogger(),
      $this->cache,
      new NullLockBackend(),
      $this->createMock(StateInterface::class),
      $time,
      $this->createMock(EntityTypeManagerInterface::class),
      $token_manager,
      $alerts,
    );
  }

  /**
   * Block settings resembling the live tour block: 2 event types, 10 days.
   */
  protected function tourSettings(): array {
    return [
      'selected_event_type_uris' => [
        'https://api.calendly.com/event_types/AAA',
        'https://api.calendly.com/event_types/BBB',
      ],
      'event_type_keywords' => '',
      'days_to_show' => 10,
    ];
  }

  /**
   * Queues the full happy-path response sequence for tourSettings().
   *
   * 1 users/me + 2 event type details + (2 event types x 2 chunks) = 7.
   */
  protected function queueHappyPath(): void {
    $this->mockHandler->append(
      new Response(200, [], json_encode([
        'resource' => [
          'uri' => 'https://api.calendly.com/users/ME',
          'name' => 'Staff Person',
        ],
      ])),
      new Response(200, [], json_encode([
        'resource' => [
          'uri' => 'https://api.calendly.com/event_types/AAA',
          'name' => 'Tour With Alice',
          'scheduling_url' => 'https://calendly.com/alice/tour',
          'profile' => ['type' => 'User', 'name' => 'Alice A', 'owner' => 'https://api.calendly.com/users/ALICE'],
        ],
      ])),
      new Response(200, [], json_encode([
        'resource' => [
          'uri' => 'https://api.calendly.com/event_types/BBB',
          'name' => 'Tour With Bob',
          'scheduling_url' => 'https://calendly.com/bob/tour',
          'profile' => ['type' => 'User', 'name' => 'Bob B', 'owner' => 'https://api.calendly.com/users/BOB'],
        ],
      ])),
      // Availability chunks. Includes a duplicate slot (same start_time and
      // booking_url) across chunks and an unavailable slot, plus
      // out-of-order times to prove dedupe and sorting.
      new Response(200, [], json_encode([
        'collection' => [
          ['status' => 'available', 'start_time' => '2026-07-12T15:00:00Z', 'invitees_remaining' => 3],
          ['status' => 'not-available', 'start_time' => '2026-07-12T16:00:00Z', 'invitees_remaining' => 0],
        ],
      ])),
      new Response(200, [], json_encode([
        'collection' => [
          ['status' => 'available', 'start_time' => '2026-07-12T15:00:00Z', 'invitees_remaining' => 3],
        ],
      ])),
      new Response(200, [], json_encode([
        'collection' => [
          ['status' => 'available', 'start_time' => '2026-07-11T10:00:00Z', 'invitees_remaining' => 5],
        ],
      ])),
      new Response(200, [], json_encode(['collection' => []])),
    );
  }

  /**
   * @covers ::refresh
   */
  public function testRefreshCollectsDedupesAndSortsSlots(): void {
    $repository = $this->buildRepository();
    $this->queueHappyPath();

    $payload = $repository->refresh($this->tourSettings());

    $this->assertNotNull($payload);
    $this->assertSame($this->now, $payload['fetched_at']);
    // 3 available entries queued, one is a duplicate: 2 unique slots.
    $this->assertCount(2, $payload['slots']);
    // Sorted chronologically.
    $this->assertSame('2026-07-11T10:00:00Z', $payload['slots'][0]['start_time']);
    // Owner names resolved from the profile object without extra calls.
    $owners = array_column($payload['slots'], 'event_owner_name');
    $this->assertContains('Alice A', $owners);
    // Every queued response was consumed — no extra or missing requests.
    $this->assertSame(0, $this->mockHandler->count());
  }

  /**
   * @covers ::getSlots
   */
  public function testGetSlotsServesCacheWithoutApiCalls(): void {
    $repository = $this->buildRepository();
    $this->queueHappyPath();
    $repository->refresh($this->tourSettings());

    // Fresh cache: no responses queued, so any API call would throw.
    $payload = $repository->getSlots($this->tourSettings());
    $this->assertNotNull($payload);
    $this->assertCount(2, $payload['slots']);
  }

  /**
   * @covers ::getSlots
   */
  public function testGetSlotsFallsBackToStaleDataWhenRefreshFails(): void {
    $repository = $this->buildRepository();
    $this->queueHappyPath();
    $repository->refresh($this->tourSettings());

    // Age the cache past the inline-refresh threshold, then make the
    // refresh fail hard (users/me 500).
    $this->now += CalendlySlotRepository::INLINE_REFRESH_AFTER + 10;
    $this->mockHandler->append(new Response(500, [], 'server error'));

    $payload = $repository->getSlots($this->tourSettings());
    $this->assertNotNull($payload, 'Stale data is served when refresh fails.');
    $this->assertCount(2, $payload['slots']);
    $this->assertSame($this->now - CalendlySlotRepository::INLINE_REFRESH_AFTER - 10, $payload['fetched_at']);
  }

  /**
   * @covers ::getSlots
   */
  public function testGetSlotsReturnsNullWithoutTokenOrCache(): void {
    $repository = $this->buildRepository(NULL);
    $this->assertNull($repository->getSlots($this->tourSettings()));
  }

  /**
   * @covers ::cacheKey
   */
  public function testCacheKeyIsStableAcrossUriOrderAndTypes(): void {
    $repository = $this->buildRepository();
    $a = $repository->cacheKey([
      'selected_event_type_uris' => ['https://x/1', 'https://x/2'],
      'event_type_keywords' => 'Tour',
      'days_to_show' => '15',
    ]);
    $b = $repository->cacheKey([
      'selected_event_type_uris' => ['https://x/2', 'https://x/1'],
      'event_type_keywords' => 'tour',
      'days_to_show' => 15,
    ]);
    $this->assertSame($a, $b);
    $c = $repository->cacheKey([
      'selected_event_type_uris' => ['https://x/1'],
      'event_type_keywords' => 'tour',
      'days_to_show' => 15,
    ]);
    $this->assertNotSame($a, $c);
  }

}
