<?php

namespace Drupal\crossref_api_client\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Crossref API Client settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'crossref_api_client_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['crossref_api_client.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Contact Email'),
      '#description' => $this->t('Email addresses will be added to each API query in a mailto parameter. By doing this Crossref will direct your requests to a special pool of servers reserved for "polite" users. Crossref will contact this address in the event that they need to communicate about problems with use of the API. So this email address should be monitored. See the <a href=":url">API documentation</a> for more information.', [':url' => 'https://api.crossref.org/swagger-ui/index.html']),
      '#default_value' => $this->config('crossref_api_client.settings')->get('email'),
    ];
    $form['token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Plus service API token'),
      '#description' => $this->t("If you have signed up for Crossref's Plus service, then you will be issued an API authorization token which you can enter here. If you want to keep the token out of your site's configuration, for instance if your site's configuration is hosted publicly on GitHub, then it is recommended to enter a fake value here and then override it in your site's settings.php file."),
      '#maxlength' => 250,
    ];
    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log request metadata for debugging'),
      '#description' => $this->t('It is recommended to turn this setting off in production unless you are experiencing problems.'),
      '#default_value' => $this->config('crossref_api_client.settings')->get('debug'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('crossref_api_client.settings')
      ->set('email', $form_state->getValue('email'))
      ->set('token', $form_state->getValue('token'))
      ->set('debug', $form_state->getValue('debug'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
