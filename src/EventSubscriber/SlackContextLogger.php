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
 */
final class SlackContextLogger implements EventSubscriberInterface {

  /**
   * Debug-data key written to ai_log.extra_data.
   */
  public const CONTEXT_KEY = 'mh_slack_context';

  /**
   * {@inheritdoc}
   *
   * Ahead of ai_logging's subscriber (priority 0), like AnswerLogSubscriber.
   */
  public static function getSubscribedEvents(): array {
    return [
      PostGenerateResponseEvent::EVENT_NAME => ['recordContext', 100],
    ];
  }

  /**
   * Copies the context tag into the event's debug data.
   */
  public function recordContext(PostGenerateResponseEvent $event): void {
    $context = self::contextFromTags($event->getTags());
    if ($context !== NULL) {
      $event->setDebugData(self::CONTEXT_KEY, $context);
    }
  }

  /**
   * The context named by a request's tags, or NULL if not a Slack answer.
   */
  public static function contextFromTags(array $tags): ?string {
    foreach ($tags as $tag) {
      if (is_string($tag) && str_starts_with($tag, SlackAnswerer::CONTEXT_TAG)) {
        return substr($tag, strlen(SlackAnswerer::CONTEXT_TAG));
      }
    }
    return NULL;
  }

}
