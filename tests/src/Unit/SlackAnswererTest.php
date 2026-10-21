<?php

declare(strict_types=1);

namespace Drupal\Tests\makehaven_slack_bot\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\makehaven_slack_bot\Service\SlackAnswerer;

/**
 * Which Slack events Red answers, and how answers are formatted.
 *
 * @group makehaven_slack_bot
 * @coversDefaultClass \Drupal\makehaven_slack_bot\Service\SlackAnswerer
 */
class SlackAnswererTest extends UnitTestCase {

  /**
   * An @mention becomes a question with the mention stripped.
   *
   * @covers ::questionFromEvent
   */
  public function testMentionBecomesQuestion(): void {
    $question = SlackAnswerer::questionFromEvent([
      'type' => 'app_mention',
      'user' => 'U02DJKD4F',
      'text' => '<@U0AUKCDLN9J> how do I book the laser?',
      'channel' => 'C123',
      'ts' => '1792591346.909799',
    ]);
    $this->assertSame('how do I book the laser?', $question['text']);
    $this->assertSame('U02DJKD4F', $question['user']);
    $this->assertFalse($question['im']);
    $this->assertSame('', $question['thread_ts']);
  }

  /**
   * A DM to Red is a question; a channel message without a mention is not.
   *
   * @covers ::questionFromEvent
   */
  public function testDirectMessagesOnly(): void {
    $dm = [
      'type' => 'message',
      'channel_type' => 'im',
      'user' => 'U1',
      'text' => 'hours?',
      'channel' => 'D1',
      'ts' => '1.1',
    ];
    $this->assertTrue(SlackAnswerer::questionFromEvent($dm)['im']);
    $this->assertNull(SlackAnswerer::questionFromEvent(['channel_type' => 'channel'] + $dm));
  }

  /**
   * Bots (including Red), edits and empty mentions are ignored.
   *
   * @covers ::questionFromEvent
   */
  public function testIgnoresBotsEditsAndEmptyMentions(): void {
    $mention = ['type' => 'app_mention', 'user' => 'U1', 'text' => '<@UBOT> hi', 'channel' => 'C1', 'ts' => '1.1'];
    $this->assertNull(SlackAnswerer::questionFromEvent(['bot_id' => 'B1'] + $mention));
    $this->assertNull(SlackAnswerer::questionFromEvent(['subtype' => 'message_changed'] + $mention));
    $this->assertNull(SlackAnswerer::questionFromEvent(['text' => '<@UBOT>'] + $mention));
  }

  /**
   * Markdown links, bold and headings become Slack mrkdwn.
   *
   * @covers ::toSlack
   */
  public function testToSlack(): void {
    $this->assertSame(
      "*Booking*\nUse <https://www.makehaven.org/equipment|the equipment page> or <https://example.com/x|this>. *Bring safety glasses.*",
      SlackAnswerer::toSlack("### Booking\nUse [the equipment page](/equipment) or [this](https://example.com/x). **Bring safety glasses.**"),
    );
  }

}
