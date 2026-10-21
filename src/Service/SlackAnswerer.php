<?php

declare(strict_types=1);

namespace Drupal\makehaven_slack_bot\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_assistant_api\Event\AiAssistantSystemRoleEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Answers members' Slack questions with the website's Member Navigator.
 *
 * Slack wants its event acknowledged within three seconds and an answer
 * takes longer, so the controller only queues the question here. Drupal
 * destructs this service after the response has gone back to Slack, and
 * that is when the answer is built and posted in a thread.
 *
 * The answer is the Navigator's, built the way the website builds it: the
 * assistant's instructions, then the member's own context added by
 * MemberNavigatorSystemRoleSubscriber while running as the member who asked.
 * Running as the member also lets AnswerLogSubscriber log the answer with
 * their uid and page "/slack", so Slack answers count in the same refusal
 * measurement as the website's.
 *
 * Replies go out as the Member Sync app's bot ("Red Robot"), using
 * slack_member_sync's token, and only from live: every other environment
 * holds live's credentials.
 */
final class SlackAnswerer implements DestructableInterface {

  /**
   * Page recorded for Slack questions in the context and the answer log.
   */
  const PAGE = '/slack';

  /**
   * Canonical host for links in Slack.
   */
  const SITE = 'https://www.makehaven.org';

  /**
   * Instructions added after the Navigator's own context.
   */
  const SLACK_INSTRUCTIONS = <<<'TXT'
### Answering in Slack
You are Red Robot, MakeHaven's helper, answering a member in Slack rather than on the website. Keep answers short: a few sentences or a short list. Link to the page that answers the question, as a full https://www.makehaven.org URL. Do not tell them to look at "this page" or "the card above": they are in Slack, not on a page. If you are not sure, say so in one sentence and point them to the closest page, or suggest asking staff in Slack.
TXT;

  /**
   * Questions waiting to be answered once the response has been sent.
   *
   * @var array<int, array{user: string, channel: string, ts: string, thread_ts: string, text: string, im: bool}>
   */
  private array $pending = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AiProviderPluginManager $aiProvider,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly RequestStack $requestStack,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Turns a Slack event into a question, or NULL when Red should not answer.
   *
   * Red answers an @mention anywhere, and any message in a DM with it.
   * Edits, joins and other subtypes, and anything a bot said, are ignored,
   * which also keeps Red from answering itself.
   */
  public static function questionFromEvent(array $event): ?array {
    $type = (string) ($event['type'] ?? '');
    $im = $type === 'message' && ($event['channel_type'] ?? '') === 'im';
    if ($type !== 'app_mention' && !$im) {
      return NULL;
    }
    if (!empty($event['bot_id']) || !empty($event['subtype'])) {
      return NULL;
    }
    // Drop the "<@U…>" mention of Red itself.
    $text = trim((string) preg_replace('/<@[A-Z0-9]+>/', '', (string) ($event['text'] ?? '')));
    $user = (string) ($event['user'] ?? '');
    $channel = (string) ($event['channel'] ?? '');
    $ts = (string) ($event['ts'] ?? '');
    if ($text === '' || $user === '' || $channel === '' || $ts === '') {
      return NULL;
    }
    return [
      'user' => $user,
      'channel' => $channel,
      'ts' => $ts,
      'thread_ts' => (string) ($event['thread_ts'] ?? ''),
      'text' => $text,
      'im' => $im,
    ];
  }

  /**
   * Queues a question to answer after the response is sent.
   */
  public function queue(array $question): void {
    $this->pending[] = $question;
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    foreach ($this->pending as $question) {
      try {
        $this->answer($question);
      }
      catch (\Throwable $e) {
        $this->logger()->error('Could not answer Slack question from @user: @error', [
          '@user' => $question['user'],
          '@error' => $e->getMessage(),
        ]);
      }
    }
    $this->pending = [];
  }

  /**
   * Answers one question in Slack, or stays quiet.
   */
  private function answer(array $question): void {
    $settings = $this->configFactory->get('makehaven_slack_bot.settings');
    if (!$settings->get('enabled')) {
      return;
    }
    // While Red is being tried out, only the listed people get answers.
    $allowed = array_filter((array) $settings->get('allowed_slack_users'));
    if ($allowed && !in_array($question['user'], $allowed, TRUE)) {
      return;
    }
    $env = $_ENV['PANTHEON_ENVIRONMENT'] ?? getenv('PANTHEON_ENVIRONMENT') ?: NULL;
    if ($env !== 'live') {
      $this->logger()->info('Slack question from @user not answered outside live: @text', [
        '@user' => $question['user'],
        '@text' => $question['text'],
      ]);
      return;
    }

    $member = $this->memberForSlackUser($question['user']);
    if (!$member) {
      $this->reply($question, "I can only answer for MakeHaven members whose Slack account is linked to makehaven.org, and I couldn't find yours. Staff can help with that, or ask your question in a channel.");
      return;
    }
    if ($member->hasField('field_hide_ai_chatbots') && $member->get('field_hide_ai_chatbots')->value) {
      $this->reply($question, "You've turned off AI assistants on makehaven.org, so I'll leave this one for a person to answer.");
      return;
    }

    $this->reply($question, self::toSlack($this->ask($member, $question['text'], (string) ($settings->get('assistant_id') ?: 'member_navigator'))));
  }

  /**
   * The member whose profile holds this Slack user ID, if active.
   */
  private function memberForSlackUser(string $slackUserId): ?UserInterface {
    $ids = $this->entityTypeManager->getStorage('profile')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'main')
      ->condition('field_member_slack_id_number', $slackUserId)
      ->range(0, 1)
      ->execute();
    $profile = $ids ? $this->entityTypeManager->getStorage('profile')->load(reset($ids)) : NULL;
    $user = $profile?->getOwner();
    return $user instanceof UserInterface && $user->isActive() ? $user : NULL;
  }

  /**
   * Asks the assistant as the member, the way the website's chat does.
   */
  private function ask(UserInterface $member, string $text, string $assistantId): string {
    $assistant = $this->entityTypeManager->getStorage('ai_assistant')->load($assistantId);
    if (!$assistant) {
      throw new \RuntimeException("Assistant $assistantId not found.");
    }
    $providerId = $assistant->get('llm_provider');
    $modelId = $assistant->get('llm_model');
    if ($providerId === '__default__') {
      $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat');
      $providerId = $defaults['provider_id'] ?? NULL;
      $modelId = $defaults['model_id'] ?? NULL;
    }
    if (!$providerId || !$modelId) {
      throw new \RuntimeException('No AI provider or model for the assistant.');
    }

    // The context subscribers read the DeepChat request body; this is the
    // body the website's chat would send from page /slack.
    $body = (string) json_encode([
      'assistant_id' => $assistantId,
      'contexts' => ['current_route' => self::PAGE],
      'messages' => [['role' => 'user', 'text' => $text]],
    ]);
    $this->requestStack->push(Request::create(self::SITE . '/api/deepchat', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body));
    $this->accountSwitcher->switchTo($member);
    try {
      $event = new AiAssistantSystemRoleEvent((string) ($assistant->get('instructions') ?: ''));
      $this->eventDispatcher->dispatch($event, AiAssistantSystemRoleEvent::EVENT_NAME);
      $systemPrompt = trim($event->getSystemPrompt()) . "\n\n" . self::SLACK_INSTRUCTIONS;

      $provider = $this->aiProvider->createInstance($providerId);
      $provider->setConfiguration((array) ($assistant->get('llm_configuration') ?? []));
      $input = new ChatInput([new ChatMessage('user', $text)]);
      $input->setSystemPrompt($systemPrompt);
      $tags = [
        'makehaven_slack_bot',
        'ai_assistant_api_assistant_message',
        'ai_assistant_api_assistant_message_' . $assistantId,
      ];
      return trim($provider->chat($input, $modelId, $tags)->getNormalized()->getText());
    }
    finally {
      $this->accountSwitcher->switchBack();
      $this->requestStack->pop();
    }
  }

  /**
   * Converts the assistant's Markdown to Slack mrkdwn.
   *
   * Links become <url|text> with site paths made absolute, **bold** and
   * headings become *bold*.
   */
  public static function toSlack(string $markdown): string {
    $absolute = static fn (string $url): string => str_starts_with($url, '/') ? self::SITE . $url : $url;
    $text = preg_replace_callback(
      '/\[([^\]]+)\]\(([^)\s]+)\)/',
      static fn (array $m): string => '<' . $absolute($m[2]) . '|' . str_replace(['<', '>', '|'], '', $m[1]) . '>',
      $markdown,
    ) ?? $markdown;
    $text = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $text) ?? $text;
    $text = preg_replace('/^#{1,6}\s+(.+)$/m', '*$1*', $text) ?? $text;
    return trim($text);
  }

  /**
   * Posts a reply: in a DM directly, in a channel as a thread reply.
   */
  private function reply(array $question, string $text): void {
    if ($text === '') {
      return;
    }
    $token = trim((string) $this->configFactory->get('slack_member_sync.settings')->get('bot_token'));
    if ($token === '') {
      throw new \RuntimeException('No Slack bot token.');
    }
    $payload = [
      'channel' => $question['channel'],
      'text' => $text,
      'unfurl_links' => FALSE,
      'unfurl_media' => FALSE,
    ];
    if (!$question['im'] || $question['thread_ts'] !== '') {
      $payload['thread_ts'] = $question['thread_ts'] ?: $question['ts'];
    }
    $response = $this->httpClient->request('POST', 'https://slack.com/api/chat.postMessage', [
      'headers' => ['Authorization' => 'Bearer ' . $token],
      'json' => $payload,
      'timeout' => 10,
    ]);
    $data = (array) json_decode((string) $response->getBody(), TRUE);
    if (empty($data['ok'])) {
      throw new \RuntimeException('chat.postMessage failed: ' . ($data['error'] ?? 'unknown'));
    }
  }

  /**
   * The module's logger.
   */
  private function logger() {
    return $this->loggerFactory->get('makehaven_slack_bot');
  }

}
