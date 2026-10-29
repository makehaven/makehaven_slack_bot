<?php

namespace Drupal\makehaven_slack_bot\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Red Robot's Slack answers.
 *
 * Red Robot is its own Slack app, separate from Member Sync. Its bot token
 * and signing secret come from the SLACK_RED_BOT_TOKEN and
 * SLACK_RED_SIGNING_SECRET Pantheon secrets via settings.php; the fields here
 * are only fallbacks.
 */
class SlackBotSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makehaven_slack_bot_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['makehaven_slack_bot.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('makehaven_slack_bot.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Answer questions in Slack'),
      '#default_value' => (bool) $config->get('enabled'),
      '#description' => $this->t('Red Robot answers an @mention anywhere, and any DM to it, as a reply in thread. Answers come from the live site only.'),
    ];

    $form['allowed_slack_users'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Only answer these people'),
      '#default_value' => implode("\n", (array) $config->get('allowed_slack_users')),
      '#description' => $this->t('Slack user IDs (U…), one per line. While this has anyone in it, Red stays silent for everyone else. Empty it to open Red to all members.'),
      '#rows' => 3,
    ];

    $form['open_dms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Answer DMs from any member'),
      '#default_value' => (bool) $config->get('open_dms'),
      '#description' => $this->t('Any member whose Slack account is linked can DM Red. @mentions in channels still answer only the people listed above.'),
    ];

    $form['daily_cap'] = [
      '#type' => 'number',
      '#title' => $this->t('Questions per person per day'),
      '#min' => 0,
      '#default_value' => (int) ($config->get('daily_cap') ?? 20),
      '#description' => $this->t('After this many, Red says it has reached its limit for the day. 0 = no cap.'),
    ];

    $assistants = [];
    foreach (\Drupal::entityTypeManager()->getStorage('ai_assistant')->loadMultiple() as $id => $assistant) {
      $assistants[$id] = $assistant->label();
    }
    $form['assistant_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Assistant that answers'),
      '#options' => $assistants,
      '#default_value' => $config->get('assistant_id') ?: 'member_navigator',
      '#description' => $this->t('The same assistant as the website chat, so both give the same answers.'),
    ];

    $form['bot_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Red Robot Bot User OAuth Token (fallback)'),
      '#default_value' => $config->getOriginal('bot_token', FALSE),
      '#description' => $this->t('Normally set by the SLACK_RED_BOT_TOKEN Pantheon secret, which overrides this field. The xoxb- token under "Install App" for the Red Robot app, not the Member Sync app.'),
    ];

    $form['signing_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Slack Signing Secret (fallback)'),
      '#default_value' => $config->getOriginal('signing_secret', FALSE),
      '#description' => $this->t('Normally set by the SLACK_RED_SIGNING_SECRET Pantheon secret, which overrides this field. Found under "Basic Information" → "App Credentials" for the Red Robot app at api.slack.com/apps.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $users = preg_split('/[\s,]+/', (string) $form_state->getValue('allowed_slack_users'), -1, PREG_SPLIT_NO_EMPTY);
    $this->config('makehaven_slack_bot.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('allowed_slack_users', array_values(array_unique($users)))
      ->set('open_dms', (bool) $form_state->getValue('open_dms'))
      ->set('daily_cap', (int) $form_state->getValue('daily_cap'))
      ->set('assistant_id', $form_state->getValue('assistant_id'))
      ->set('bot_token', trim((string) $form_state->getValue('bot_token')))
      ->set('signing_secret', trim((string) $form_state->getValue('signing_secret')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
