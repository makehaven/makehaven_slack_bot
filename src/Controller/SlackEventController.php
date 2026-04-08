<?php

namespace Drupal\makehaven_slack_bot\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for handling Slack events.
 */
class SlackEventController extends ControllerBase {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The AI Agent Manager.
   *
   * @var \Drupal\ai_agents\PluginManager\AiAgentManager
   */
  protected $aiAgentManager;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new SlackEventController.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    AiAgentManager $ai_agent_manager,
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->loggerFactory = $logger_factory;
    $this->aiAgentManager = $ai_agent_manager;
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory'),
      $container->get('plugin.manager.ai_agents'),
      $container->get('http_client'),
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

    if (isset($data['type']) && $data['type'] === 'event_callback') {
      $event = $data['event'] ?? [];
      if (isset($event['type']) && $event['type'] === 'app_mention' && !isset($event['bot_id'])) {
        $this->processAppMention($event);
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

  /**
   * Process the app_mention event.
   */
  protected function processAppMention(array $event): void {
    $text = $event['text'] ?? '';
    $channel = $event['channel'] ?? '';

    try {
      $config = $this->configFactory->get('makehaven_slack_bot.settings');
      $agentId = $config->get('agent_id') ?: 'makehaven_orchestrator';

      if (!$this->aiAgentManager->hasDefinition($agentId)) {
        $this->loggerFactory->get('makehaven_slack_bot')->error('AI Agent @id not found.', ['@id' => $agentId]);
        $this->postToSlack($channel, 'Error: I seem to have lost my brain (Agent not found).');
        return;
      }

      /** @var \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent */
      $agent = $this->aiAgentManager->createInstance($agentId);

      $input = new ChatInput([new ChatMessage('user', $text)]);
      if (method_exists($agent, 'setChatInput')) {
        $agent->setChatInput($input);
      }

      $responseText = NULL;
      if (method_exists($agent, 'determineSolvability')) {
        $agent->determineSolvability();
        $responseText = $agent->answerQuestion();
      }

      if ($responseText) {
        $this->postToSlack($channel, $responseText);
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('makehaven_slack_bot')->error('Error processing AI request: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Posts a message to a Slack channel using the Web API.
   */
  protected function postToSlack(string $channel, string $text): void {
    $token = trim((string) $this->configFactory->get('makehaven_slack_bot.settings')->get('bot_token'));
    if ($token === '') {
      $this->loggerFactory->get('makehaven_slack_bot')->error('Slack bot token not configured.');
      return;
    }

    try {
      $this->httpClient->post('https://slack.com/api/chat.postMessage', [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json; charset=utf-8',
        ],
        'json' => [
          'channel' => $channel,
          'text' => $text,
        ],
      ]);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('makehaven_slack_bot')->error('Failed to send Slack message: @error', ['@error' => $e->getMessage()]);
    }
  }

}
