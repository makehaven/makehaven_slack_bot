<?php

declare(strict_types=1);

namespace Drupal\makehaven_slack_bot\EventSubscriber;

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\makehaven_slack_bot\Service\SlackAnswerer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records in ai_log which context a Slack answer had.
 *
 * SlackAnswerer tags each request with the context it sent (DM history, a
 * thread, or the message alone). This copies that into the log's extra data
 * as `mh_slack_context`, beside AnswerLogSubscriber's `mh_page`, so refusal
 * and quality checks can tell the three apart.
 *
 * When Red hands a question to the tool assistant, that request is made "on"
 * the tool or badge page, so AnswerLogSubscriber records that page. This puts
 * `mh_page` back to /slack, so every Slack answer counts in the Slack refusal
 * measurement, and keeps the page as `mh_slack_handoff`.
 */
final class SlackContextLogger implements EventSubscriberInterface {

  /**
   * Debug-data key written to ai_log.extra_data.
   */
  public const CONTEXT_KEY = 'mh_slack_context';

  /**
   * Debug-data key for the page a question was handed to.
   */
  public const HANDOFF_KEY = 'mh_slack_handoff';

  /**
   * {@inheritdoc}
   *
   * After AnswerLogSubscriber (100), whose page it corrects, and ahead of
   * ai_logging's subscriber (0), which writes the row.
   */
  public static function getSubscribedEvents(): array {
    return [
      PostGenerateResponseEvent::EVENT_NAME => ['recordContext', 90],
    ];
  }

  /**
   * Copies the context tag into the event's debug data.
   */
  public function recordContext(PostGenerateResponseEvent $event): void {
    $tags = $event->getTags();
    $context = self::valueFromTags($tags, SlackAnswerer::CONTEXT_TAG);
    if ($context === NULL) {
      return;
    }
    $event->setDebugData(self::CONTEXT_KEY, $context);
    $handoff = self::valueFromTags($tags, SlackAnswerer::HANDOFF_TAG);
    if ($handoff !== NULL) {
      $event->setDebugData(self::HANDOFF_KEY, $handoff);
      $event->setDebugData('mh_page', SlackAnswerer::PAGE);
    }
  }

  /**
   * The rest of the first tag starting with a prefix, or NULL.
   */
  public static function valueFromTags(array $tags, string $prefix): ?string {
    foreach ($tags as $tag) {
      if (is_string($tag) && str_starts_with($tag, $prefix)) {
        return substr($tag, strlen($prefix));
      }
    }
    return NULL;
  }

}
