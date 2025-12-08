<?php

namespace Drupal\makehaven_slack_bot\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;

/**
 * Configure MakeHaven Slack Bot settings for this site.
 */
class SlackBotSettingsForm extends ConfigFormBase {

  /**
   * The AI Agent Manager.
   *
   * @var \Drupal\ai_agents\PluginManager\AiAgentManager
   */
  protected $aiAgentManager;

  /**
   * Constructs a new SlackBotSettingsForm.
   *
   * @param \Drupal\ai_agents\PluginManager\AiAgentManager $ai_agent_manager
   *   The AI Agent manager.
   */
  public function __construct(AiAgentManager $ai_agent_manager) {
    $this->aiAgentManager = $ai_agent_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.ai_agents')
    );
  }

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

    $form['bot_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bot Name'),
      '#default_value' => $config->get('bot_name') ?: 'MakeHaven Bot',
      '#description' => $this->t('The name of the bot as it appears in logs or messages (if applicable).'),
    ];

    $form['signing_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Slack Signing Secret'),
      '#default_value' => $config->get('signing_secret'),
      '#description' => $this->t('The Signing Secret from your Slack App credentials. Found under "Basic Information" -> "App Credentials" -> "Signing Secret" in your Slack API settings (api.slack.com/apps).'),
      '#required' => TRUE,
    ];

    // Get available agents
    $agents = $this->aiAgentManager->getDefinitions();
    $agent_options = [];
    foreach ($agents as $id => $definition) {
      $agent_options[$id] = $definition['label'] ?? $id;
    }

    $form['agent_id'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Agent'),
      '#options' => $agent_options,
      '#default_value' => $config->get('agent_id') ?: 'makehaven_orchestrator',
      '#description' => $this->t('Select the AI Agent that will process the Slack messages.'),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select an Agent -'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('makehaven_slack_bot.settings')
      ->set('bot_name', $form_state->getValue('bot_name'))
      ->set('signing_secret', $form_state->getValue('signing_secret'))
      ->set('agent_id', $form_state->getValue('agent_id'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
