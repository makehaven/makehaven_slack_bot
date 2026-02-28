<?php

namespace Drupal\makehaven_slack_bot\EventSubscriber;

use Drupal\ai_assistant_api\Event\AiAssistantSystemRoleEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Injects live tool node fields into AI assistant system prompts.
 *
 * Fires on the AiAssistantSystemRoleEvent, which is dispatched during the
 * DeepChat API request. At that point the current route is /api/deepchat, not
 * the tool page, so the node ID is read from the request body's
 * contexts.current_route value (e.g. /node/424) that the block sends.
 *
 * TODO: This belongs in a dedicated module (e.g. makehaven_ai_context).
 * It lives here temporarily because makehaven_slack_bot is the only custom
 * module already committed directly to this repo.
 */
class ToolPageContextSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiAssistantSystemRoleEvent::EVENT_NAME => 'injectToolContext',
    ];
  }

  /**
   * Appends tool node data to the AI system prompt when on a tool page.
   */
  public function injectToolContext(AiAssistantSystemRoleEvent $event): void {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }

    // The DeepChat block sends the current page path in the POST body.
    $body = json_decode($request->getContent(), TRUE);
    $current_route = $body['contexts']['current_route'] ?? NULL;

    // Only act on /node/NID paths.
    if (!$current_route || !preg_match('|^/node/(\d+)$|', $current_route, $matches)) {
      return;
    }

    $nid = (int) $matches[1];
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== 'item' || !$node->access('view')) {
      return;
    }

    $lines = [];
    $lines[] = "\n\n---";
    $lines[] = "## Live Tool Page Data";
    $lines[] = "Tool: " . $node->getTitle();

    // Body description.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body_text = strip_tags($node->get('body')->value);
      $body_text = mb_substr(trim($body_text), 0, 1500);
      if ($body_text) {
        $lines[] = "Description: " . $body_text;
      }
    }

    // Staff-authored AI context field.
    if ($node->hasField('field_item_ai_context') && !$node->get('field_item_ai_context')->isEmpty()) {
      $lines[] = "";
      $lines[] = "Staff notes for AI assistants:";
      $lines[] = $node->get('field_item_ai_context')->value;
    }

    // Required badges/certifications (field_additional_badges → badges vocab).
    if ($node->hasField('field_additional_badges') && !$node->get('field_additional_badges')->isEmpty()) {
      $badge_names = [];
      foreach ($node->get('field_additional_badges')->referencedEntities() as $term) {
        $badge_names[] = $term->label();
      }
      if ($badge_names) {
        $lines[] = "Required badges/certifications: " . implode(', ', $badge_names);
      }
    }

    $lines[] = "---";

    $event->setSystemPrompt($event->getSystemPrompt() . implode("\n", $lines));
  }

}
