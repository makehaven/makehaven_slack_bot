<?php

namespace Drupal\makehaven_slack_bot\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

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
   * The Slack Service.
   *
   * @var object
   */
  protected $slackService;

  /**
   * Constructs a new SlackEventController.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\ai_agents\PluginManager\AiAgentManager $ai_agent_manager
   *   The AI Agent manager.
   * @param object $slack_service
   *   The Slack service.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, AiAgentManager $ai_agent_manager, $slack_service) {
    $this->loggerFactory = $logger_factory;
    $this->aiAgentManager = $ai_agent_manager;
    $this->slackService = $slack_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory'),
      $container->get('plugin.manager.ai_agents'),
      $container->get('slack.slack_service')
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
  public function handleEvent(Request $request) {
    // 1. Security: Verify Signature
    if (!$this->verifySignature($request)) {
      $this->loggerFactory->get('makehaven_slack_bot')->warning('Invalid Slack signature.');
      return new Response('Invalid signature', 403);
    }

    // 2. Parse Payload
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    if (!$data) {
      return new Response('Invalid JSON', 400);
    }

    // 3. Handle URL Verification (Handshake)
    if (isset($data['type']) && $data['type'] === 'url_verification') {
      return new Response($data['challenge']);
    }

    // 4. Handle Event Callback
    if (isset($data['type']) && $data['type'] === 'event_callback') {
      $event = $data['event'] ?? [];
      
      // We only care about app_mention (or message, depending on bot scope)
      if (isset($event['type']) && $event['type'] === 'app_mention') {
        // Don't reply to self (bots)
        if (isset($event['bot_id'])) {
          return new Response('OK');
        }

        $this->processAppMention($event);
      }
    }

    return new Response('OK');
  }

  /**
   * Verify the X-Slack-Signature header.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function verifySignature(Request $request) {
    $secret = $this->config('makehaven_slack_bot.settings')->get('signing_secret');
    if (!$secret) {
      $this->loggerFactory->get('makehaven_slack_bot')->error('Slack signing secret not configured in settings.');
      // Fail secure
      return FALSE;
    }

    $signature = $request->headers->get('X-Slack-Signature');
    $timestamp = $request->headers->get('X-Slack-Request-Timestamp');

    if (!$signature || !$timestamp) {
      return FALSE;
    }

    // Prevent replay attacks (5 minutes tolerance)
    if (abs(time() - $timestamp) > 300) {
      return FALSE;
    }

    $body = $request->getContent();
    $base_string = 'v0:' . $timestamp . ':' . $body;
    $hash = 'v0=' . hash_hmac('sha256', $base_string, $secret);

    return hash_equals($hash, $signature);
  }

  /**
   * Process the app_mention event.
   *
   * @param array $event
   *   The event data.
   */
  protected function processAppMention(array $event) {
    $text = $event['text'] ?? '';
    $channel = $event['channel'] ?? '';
    
    try {
      // Get configured Agent ID, default to 'makehaven_orchestrator'
      $config = $this->config('makehaven_slack_bot.settings');
      $agentId = $config->get('agent_id') ?: 'makehaven_orchestrator';
      $botName = $config->get('bot_name');
      
      if (!$this->aiAgentManager->hasDefinition($agentId)) {
         $this->loggerFactory->get('makehaven_slack_bot')->error('AI Agent @id not found.', ['@id' => $agentId]);
         $this->slackService->sendMessage('Error: I seem to have lost my brain (Agent not found).', $channel, $botName);
         return;
      }

      /** @var \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent */
      $agent = $this->aiAgentManager->createInstance($agentId);

      // Setup Chat Input
      $message = new ChatMessage('user', $text);
      $input = new ChatInput([$message]);
      
      if (method_exists($agent, 'setChatInput')) {
        $agent->setChatInput($input);
      }

      // Execute
      if (method_exists($agent, 'determineSolvability')) {
        $agent->determineSolvability();
        $responseText = $agent->answerQuestion();
      } else {
        $responseText = "Error: Agent execution method not found.";
      }

      // Send response back to Slack
      if ($responseText) {
        // sendMessage($message, $channel, $username = NULL, $icon_emoji = NULL, $icon_url = NULL)
        $this->slackService->sendMessage($responseText, $channel, $botName);
      }

    } catch (\Exception $e) {
      $this->loggerFactory->get('makehaven_slack_bot')->error('Error processing AI request: @message', ['@message' => $e->getMessage()]);
    }
  }

}