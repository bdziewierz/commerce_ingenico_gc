<?php

namespace Drupal\commerce_ingenico_gc\PluginForm\OffsiteRedirect;

use Drupal;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
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

class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_ingenico_gc\Plugin\Commerce\PaymentGateway $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $config = $payment_gateway_plugin->getConfiguration();
    $commerce_order = $payment->getOrder();
    $current_language = \Drupal::languageManager()->getCurrentLanguage();
    $endpoint = $payment_gateway_plugin->getPaymentAPIEndpoint();

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
    $client->setClientMetaInfo("consumer specific JSON meta info");

    // Create request
    $request_order = new Order();

    $request_order->amountOfMoney = new AmountOfMoney();
    $request_order->amountOfMoney->amount = doubleval($commerce_order->getTotalPrice()->getNumber()) * 100;
    $request_order->amountOfMoney->currencyCode = $commerce_order->getTotalPrice()->getCurrencyCode();

    $request_order->customer = new Customer();
    $request_order->customer->billingAddress = new Address();
    $request_order->customer->billingAddress->countryCode = 'US'; // TODO: Capture from the billing? Why this is needed?
    $request_order->customer->merchantCustomerId = $commerce_order->id();

    $hosted_checkout_specific_input = new HostedCheckoutSpecificInput();
    $hosted_checkout_specific_input->locale = $current_language->getId();
    $hosted_checkout_specific_input->showResultPage = false;
    $hosted_checkout_specific_input->returnUrl = $form['#return_url'];

    $request = new CreateHostedCheckoutRequest();
    $request->hostedCheckoutSpecificInput = $hosted_checkout_specific_input;
    $request->order = $request_order;

    try {
      $response = $client->merchant($config['merchant_id'])->hostedcheckouts()->create($request);
      $return_mac = $response->RETURNMAC;
      $hosted_checkout_id = $response->hostedCheckoutId;
      $partial_url = $response->partialRedirectUrl;

      // TODO: Validate response

      // Persist required macs and ids
      $commerce_order->setData('commerce_ingenico_gc', [
        'return_mac' => $return_mac,
        'hosted_checkout_id' => $hosted_checkout_id,
      ]);
      $commerce_order->save();

      // Build redirect URL and execute redirect
      $redirect_url = 'https://' . implode('.', [$config['subdomain'], $partial_url]);
      return $this->buildRedirectForm($form, $form_state, $redirect_url, []);
    }
    catch(\Ingenico\Connect\Sdk\ValidationException $e) {
      throw new PaymentGatewayException($e->getMessage());
    }
  }
}
