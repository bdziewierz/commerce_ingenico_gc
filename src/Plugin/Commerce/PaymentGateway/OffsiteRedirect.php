<?php

namespace Drupal\commerce_ingenico_gc\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Ingenico\Connect\Sdk\Client;
use Ingenico\Connect\Sdk\Communicator;
use Ingenico\Connect\Sdk\DefaultConnection;
use Ingenico\Connect\Sdk\CommunicatorConfiguration;
use Ingenico\Connect\Sdk\Domain\Definitions\Address;
use Ingenico\Connect\Sdk\Domain\Definitions\AmountOfMoney;
use Ingenico\Connect\Sdk\Domain\Payment\Definitions\Order;
use Ingenico\Connect\Sdk\Domain\Payment\Definitions\Customer;
use Ingenico\Connect\Sdk\Domain\Hostedcheckout\CreateHostedCheckoutRequest;
use Ingenico\Connect\Sdk\Domain\Hostedcheckout\Definitions\HostedCheckoutSpecificInput;

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

    $return_mac = $request->get('RETURNMAC');
    $hosted_checkout_id = $request->get('hostedCheckoutId');
    $order_data = $order->getData('commerce_ingenico_gc', [
      'return_mac' => null,
      'hosted_checkout_id' => null,
    ]);

    // Validate the MAC and hosted checkout ID
    if ($order_data['return_mac'] != $return_mac) {
      throw new InvalidResponseException('RETURNMAC is invalid!');
    }

    if ($order_data['hosted_checkout_id'] != $hosted_checkout_id) {
      throw new InvalidResponseException('Hosted checkout id is invalid!');
    }

    // Conduct call back to the service to validate the status of payment
    $config = $this->getConfiguration();
    $endpoint = $this->getPaymentAPIEndpoint();

    // Validate configuration
    if (empty($config['api_key'])) {
      throw new PaymentGatewayException('API Key not provided.');
    }

    if (empty($config['api_secret'])) {
      throw new PaymentGatewayException('API Secret not provided.');
    }

    if (empty($config['integrator'])) {
      throw new PaymentGatewayException('Integrator not provided.');
    }

    if (empty($config['merchant_id'])) {
      throw new PaymentGatewayException('Merchant ID not provided.');
    }

    if (empty($config['subdomain'])) {
      throw new PaymentGatewayException('Subdomain not provided.');
    }

    // Set up Ingenico SDK client
    $communicator_configuration = new CommunicatorConfiguration(
      $config['api_key'], $config['api_secret'], $endpoint, $config['integrator']);
    $connection = new DefaultConnection();
    $communicator = new Communicator($connection, $communicator_configuration);
    $client = new Client($communicator);

    try {
      $response = $client->merchant($config['merchant_id'])->hostedcheckouts()->get($hosted_checkout_id);

      $status = $response->status;
      $payment_status = $response->createdPaymentOutput->payment->status;
      $payment_id = $response->createdPaymentOutput->payment->id;
      $status_output = $response->createdPaymentOutput->payment->statusOutput;

      if ($status != 'PAYMENT_CREATED') {
        throw new InvalidResponseException('Payment has not completed on the hosted pages.');
      }

      if ($status_output->statusCategory != 'COMPLETED') {
        throw new PaymentGatewayException('Payment has not been completed.');
      }

      if (!$status_output->isAuthorized) {
        throw new PaymentGatewayException('Payment has not been authorised.');
      }

      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment = $payment_storage->create([
        'state' => 'completed',
        'amount' => $order->getBalance(),
        'payment_gateway' => $this->parentEntity->id(),
        'order_id' => $order->id(),
        'remote_id' => $payment_id,
        'remote_state' => $payment_status,
      ]);
      $payment->save();
    }
    catch(\Ingenico\Connect\Sdk\ValidationException $e) {
      throw new PaymentGatewayException($e->getMessage());
    }
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
