<?php

namespace Drupal\makehaven_slack_bot\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\makehaven_slack_bot\Service\SlackAnswerer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Slack Events API calls for Red Robot.
 *
 * Verifies Slack's signature, answers the URL handshake, and hands questions
 * (an @mention, or a DM to Red) to SlackAnswerer, which answers after this
 * response has gone back. Slack retries any event not acknowledged within
 * three seconds; retries are acknowledged and dropped, since the first
 * delivery is already being answered.
 */
class SlackEventController extends ControllerBase {

  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected SlackAnswerer $answerer,
    ConfigFactoryInterface $config_factory,
  ) {
    // Both properties are declared, untyped, on ControllerBase.
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory'),
      $container->get('makehaven_slack_bot.answerer'),
      $container->get('config.factory'),
    );
  }

  /**
   * Handle the incoming Slack event.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function handleEvent(Request $request): Response {
    if (!$this->verifySignature($request)) {
      $this->loggerFactory->get('makehaven_slack_bot')->warning('Invalid Slack signature.');
      return new Response('Invalid signature', 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!$data) {
      return new Response('Invalid JSON', 400);
    }

    // URL verification handshake during Slack app configuration.
    if (isset($data['type']) && $data['type'] === 'url_verification') {
      return new Response($data['challenge']);
    }

    if ($request->headers->has('X-Slack-Retry-Num')) {
      return new Response('OK');
    }

    if (isset($data['type']) && $data['type'] === 'event_callback') {
      $question = SlackAnswerer::questionFromEvent($data['event'] ?? []);
      if ($question !== NULL) {
        $this->answerer->queue($question);
      }
    }

    return new Response('OK');
  }

  /**
   * Verify the X-Slack-Signature header.
   */
  protected function verifySignature(Request $request): bool {
    $secret = $this->configFactory->get('makehaven_slack_bot.settings')->get('signing_secret');
    if (!$secret) {
      $this->loggerFactory->get('makehaven_slack_bot')->error('Slack signing secret not configured.');
      return FALSE;
    }

    $signature = $request->headers->get('X-Slack-Signature');
    $timestamp = $request->headers->get('X-Slack-Request-Timestamp');

    if (!$signature || !$timestamp) {
      return FALSE;
    }

    // Prevent replay attacks (5-minute tolerance).
    if (abs(time() - (int) $timestamp) > 300) {
      return FALSE;
    }

    $hash = 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $request->getContent(), $secret);
    return hash_equals($hash, $signature);
  }

}
