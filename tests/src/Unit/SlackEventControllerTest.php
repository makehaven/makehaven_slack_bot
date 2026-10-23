<?php

declare(strict_types=1);

namespace Drupal\Tests\makehaven_slack_bot\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai\AiProviderPluginManager;
use Drupal\makehaven_slack_bot\Controller\SlackEventController;
use Drupal\makehaven_slack_bot\Service\SlackAnswerer;
use GuzzleHttp\ClientInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for SlackEventController.
 *
 * Covers signature verification, the URL verification challenge and
 * retries. Which events become questions is SlackAnswererTest's job.
 *
 * @group makehaven_slack_bot
 * @coversDefaultClass \Drupal\makehaven_slack_bot\Controller\SlackEventController
 */
class SlackEventControllerTest extends UnitTestCase {

  /**
   * The test signing secret.
   */
  protected const SECRET = 'test_signing_secret_abc123';

  /**
   * Builds a controller with faked dependencies.
   */
  protected function buildController(?string $secret = self::SECRET): SlackEventController {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['signing_secret', $secret],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    // Nothing is answered during the request: the answerer only works in
    // destruct(), which these tests never reach.
    $answerer = new SlackAnswerer(
      $configFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      // Final, so it can't be mocked; these tests never call it.
      (new \ReflectionClass(AiProviderPluginManager::class))->newInstanceWithoutConstructor(),
      $this->createMock(EventDispatcherInterface::class),
      new RequestStack(),
      $this->createMock(AccountSwitcherInterface::class),
      $this->createMock(ClientInterface::class),
      $loggerFactory,
      $this->createMock(StateInterface::class),
    );

    return new SlackEventController($loggerFactory, $answerer, $configFactory);
  }

  /**
   * Builds a request with a valid Slack signature header.
   */
  protected function buildSignedRequest(string $body, string $secret = self::SECRET, int $timestamp = 0): Request {
    if ($timestamp === 0) {
      $timestamp = time();
    }

    $hash = 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $body, $secret);

    $request = Request::create('/api/slack/events', 'POST', [], [], [], [], $body);
    $request->headers->set('X-Slack-Signature', $hash);
    $request->headers->set('X-Slack-Request-Timestamp', (string) $timestamp);
    return $request;
  }

  /**
   * Returns 403 when the Slack signature is wrong.
   *
   * @covers ::handleEvent
   * @covers ::verifySignature
   */
  public function testRejectsInvalidSignature(): void {
    $controller = $this->buildController();
    $body = json_encode(['type' => 'url_verification', 'challenge' => 'abc']);

    $request = $this->buildSignedRequest($body, 'wrong_secret');

    $response = $controller->handleEvent($request);

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Returns 403 when the signing secret is not configured.
   *
   * @covers ::verifySignature
   */
  public function testRejectsWhenSigningSecretNotConfigured(): void {
    $controller = $this->buildController(secret: NULL);
    $body = json_encode(['type' => 'url_verification', 'challenge' => 'abc']);

    $request = $this->buildSignedRequest($body);

    $response = $controller->handleEvent($request);

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Returns 403 when the request timestamp is more than 5 minutes old (replay attack).
   *
   * @covers ::verifySignature
   */
  public function testRejectsStaleTimestamp(): void {
    $controller = $this->buildController();
    $body = json_encode(['type' => 'url_verification', 'challenge' => 'abc']);

    $staleTimestamp = time() - 400; // 6+ minutes ago
    $request = $this->buildSignedRequest($body, self::SECRET, $staleTimestamp);

    $response = $controller->handleEvent($request);

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Echoes the challenge for Slack's url_verification handshake.
   *
   * @covers ::handleEvent
   */
  public function testReturnsUrlVerificationChallenge(): void {
    $controller = $this->buildController();
    $body = json_encode(['type' => 'url_verification', 'challenge' => 'my_challenge_token']);

    $request = $this->buildSignedRequest($body);

    $response = $controller->handleEvent($request);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('my_challenge_token', $response->getContent());
  }

  /**
   * Returns 400 for malformed (non-JSON) payloads.
   *
   * @covers ::handleEvent
   */
  public function testReturnsBadRequestForInvalidJson(): void {
    $controller = $this->buildController();
    $body = 'this is not json';

    $request = $this->buildSignedRequest($body);

    $response = $controller->handleEvent($request);

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Returns 200 OK for event callbacks that are not app_mention.
   *
   * @covers ::handleEvent
   */
  public function testIgnoresNonMentionEvents(): void {
    $controller = $this->buildController();
    $body = json_encode([
      'type' => 'event_callback',
      'event' => ['type' => 'message', 'text' => 'hello'],
    ]);

    $request = $this->buildSignedRequest($body);

    $response = $controller->handleEvent($request);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('OK', $response->getContent());
  }

  /**
   * Acknowledges Slack's retries without answering again.
   *
   * @covers ::handleEvent
   */
  public function testAcknowledgesRetries(): void {
    $controller = $this->buildController();
    $body = json_encode([
      'type' => 'event_callback',
      'event' => ['type' => 'app_mention', 'user' => 'U1', 'text' => '<@UBOT> hi', 'channel' => 'C1', 'ts' => '1.2'],
    ]);

    $request = $this->buildSignedRequest($body);
    $request->headers->set('X-Slack-Retry-Num', '1');

    $response = $controller->handleEvent($request);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('OK', $response->getContent());
  }

  /**
   * Valid signature accepts a fresh timestamp within the 5-minute window.
   *
   * @covers ::verifySignature
   */
  public function testAcceptsValidSignatureWithFreshTimestamp(): void {
    $controller = $this->buildController();
    $body = json_encode(['type' => 'url_verification', 'challenge' => 'fresh_token']);

    // Exactly at the boundary (299 seconds ago) — should pass.
    $timestamp = time() - 299;
    $request = $this->buildSignedRequest($body, self::SECRET, $timestamp);

    $response = $controller->handleEvent($request);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('fresh_token', $response->getContent());
  }

}
