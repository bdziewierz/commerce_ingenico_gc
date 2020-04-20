<?php

namespace Drupal\commerce_ingenico_gc\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "ingenico_gc_offsite_redirect",
 *   label = "Ingenico GlobalConnect (Off-site redirect)",
 *   display_label = "Ingenico GlobalConnect",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_ingenico_gc\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_key' => '',
      'api_secret' => '',
      'integrator' => '',
      'merchant_id' => '',
      'subdomain' => 'payment'
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $this->configuration['api_key'],
      '#required' => TRUE,
    ];

    $form['api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API secret'),
      '#default_value' => $this->configuration['api_secret'],
      '#required' => TRUE,
    ];

    $form['integrator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Integrator'),
      '#default_value' => $this->configuration['integrator'],
      '#required' => TRUE,
    ];

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => TRUE,
    ];

    $form['subdomain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subdomain'),
      '#default_value' => $this->configuration['subdomain'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['api_key'] = $values['api_key'];
      $this->configuration['api_secret'] = $values['api_secret'];
      $this->configuration['integrator'] = $values['integrator'];
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['subdomain'] = $values['subdomain'];
    }
  }

  /**
   * Return correct payment API endpoint
   *
   * @return string
   */
  public function getPaymentAPIEndpoint() {
    $config = $this->getConfiguration();
    $endpoint = 'https://world.api-ingenico.com';
    if ($config['mode'] == 'test') {
      $endpoint = 'https://eu.sandbox.api-ingenico.com';
    }
    return $endpoint;
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    parent::onReturn($order, $request);

    // @todo Add examples of request validation.
    // Note: Since requires_billing_information is FALSE, the order is
    // not guaranteed to have a billing profile. Confirm that
    // $order->getBillingProfile() is not NULL before trying to use it.
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'completed',
      'amount' => $order->getBalance(),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $request->query->get('txn_id'),
      'remote_state' => $request->query->get('payment_status'),
    ]);
    $payment->save();
  }


  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    parent::onNotify($request);
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    parent::onCancel($order, $request);
  }


  /**
   * Helper function to handle the transaction for both onNotify and onReturn.
   *
   * @param $config
   * @param $order
   * @param $response
   */
  private function handleTransaction($response) {
  }
}
