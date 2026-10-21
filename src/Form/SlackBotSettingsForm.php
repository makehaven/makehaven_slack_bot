<?php

namespace Drupal\makehaven_slack_bot\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Red Robot's Slack answers.
 *
 * The bot token is slack_member_sync's (Red is the Member Sync app). The
 * signing secret comes from the SLACK_RED_SIGNING_SECRET Pantheon secret via
 * settings.php; the field here is only a fallback.
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

    $form['signing_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Slack Signing Secret (fallback)'),
      '#default_value' => $config->getOriginal('signing_secret', FALSE),
      '#description' => $this->t('Normally set by the SLACK_RED_SIGNING_SECRET Pantheon secret, which overrides this field. Found under "Basic Information" → "App Credentials" at api.slack.com/apps.'),
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
      ->set('assistant_id', $form_state->getValue('assistant_id'))
      ->set('signing_secret', trim((string) $form_state->getValue('signing_secret')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
