<?php

// load dependencies <https://github.com/mollie/mollie-api-php>
require dirname(__FILE__) . '/library/Mollie/API/Autoloader.php';

/**
 * Cartthrob Mollie Gateway
 *
 * @package   Cartthrob Mollie Gateway
 * @author    Aphichat Panjamanee <panjamanee@gmail.com>
 * @copyright Copyright (c) 2014 Aphichat
 * @version 2.0
 **/
class Cartthrob_mollie_ideal extends Cartthrob_payment_gateway
{
  public $title = 'Mollie iDeal';
  public $settings = array(
    array(
      'name' => 'Test Mode',
      'short_name' => 'test_mode',
      'type' => 'radio',
      'default' => 'Test',
      'options' => array(
        'true' => 'On',
        'false' => 'Off'
      ) 
    ),
    array(
      'name' =>  'Live API key',
      'short_name' => 'apikey_live',
      'type' => 'text', 
      'default' => '', 
    ),
    array(
      'name' =>  'Test API key',
      'short_name' => 'apikey_test',
      'type' => 'text',
      'default' => '',
    ),
    array(
      'name' =>  'Webhook Report Email',
      'short_name' => 'webhook_report_email',
      'type' => 'text', 
      'default' => '', 
    )
  );
  public $required_fields = array(
    'first_name',
    'last_name',
    'address',
    'address2',
    'city',
    'zip',
    'email_address',
  );
  public $fields = array(
    'first_name',
    'last_name',
    'address',
    'address2',
    'city',
    'zip',
    'phone',
    'email_address',
    'country_code',
    'card_type'
  );
  
  /**
   * Initializer
   **/
  public function initialize()
  {
    // -------------------------------------------
    //  Initialize the Gateway, load bank list & render test-mode box
    // -------------------------------------------

    if($this->plugin_settings('test_mode') == 'false')
    {
      $this->apikey = $this->plugin_settings('apikey_live');
    }
    else
    {
      $this->apikey = $this->plugin_settings('apikey_test');
      $this->form_extra = '<div style="clear:both;"></div>';
      $this->form_extra .= '<p style="padding:.8em;margin:1em 0;border:1px solid #ddd;background:#FFF6BF;color:#514721;border-color:#FFD324;">Mollie iDeal testmode is on. Turn this off in Cartthrob Gateway setting</p>';
    }

    if($this->plugin_settings('apikey_live') != '' || $this->plugin_settings('apikey_test'))
    {
      $mollie = new Mollie_API_Client;
      $mollie->setApiKey($this->apikey);

      $methods = $mollie->methods->all();
      $pay_method = array();

      $count = 0;

      foreach ($methods as $method)
      {
        $pay_method[htmlspecialchars($method->id)] = htmlspecialchars($method->id);
      }
      
      $this->card_types = array_values($pay_method);
      $this->card_ids = array_keys($pay_method);
    }
  }

  /**
   * process_payment
   *
   * @access public
   * @return array $resp an array containing the following keys: authorized, declined, failed, error_message, and transaction_id 
   * the returned fields can be displayed in the templates using template tags. 
   **/
  public function charge()
  {
    // -------------------------------------------
    //  Order will fail by default
    // -------------------------------------------
    $resp = array();
    $method = $this->card_ids[array_search($this->order('card_type'), $this->card_types)];

    $resp['authorized'] = false;
    $resp['declined'] = false;
    $resp['failed'] = false;
    $resp['error_message'] = '';
    $resp['transaction_id'] = '';

    $mollie = new Mollie_API_Client;
    $mollie->setApiKey($this->apikey);

    $protocol = isset($_SERVER['HTTPS']) && strcasecmp('off', $_SERVER['HTTPS']) !== 0 ? "https" : "http";
    $hostname = $_SERVER['HTTP_HOST'];
    $path = dirname(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : $_SERVER['PHP_SELF']);
    $order_id = $this->order('entry_id');
    $total = $this->order('total');
    $webhookUrl =  $this->response_script(ucfirst(get_class($this)));
    $payment = $mollie->payments->create(array(
      'amount' => $total,
      'method' => $method,
      'description' => 'Order: '.$this->order('entry_id').' '.($this->order('description') ? $this->order('description') : ' - '.$this->order('site_name')),
      'redirectUrl' => "{$protocol}://{$hostname}/cart/status?order_id={$order_id}",
      'webhookUrl' => $webhookUrl,
      'metadata' => array(
        'order_id' => $order_id,
      ),
    ));


    $this->gateway_exit_offsite('', $payment->getPaymentUrl());
    return $resp;
  }

  /**
   * @param $post
   * @link https://cartthrob.com/forums/viewthread/3892/#18711
   * Calls from an external site (mollie.com) as a $webhookUrl var that has been sent from charge() method.
   * Ships with all posts vars!
  **/
  function extload($post = array())
  { 
    $this->confirm_payment($post);
  } 

  /**
   * @param $post
   * @link https://www.mollie.nl/files/documentatie/payments-api.html
   * @todo Need to catch error codes and put them into EE's order
   * @todo The cart in EE will not being emptied if Mollie cannot do Webhook check and no message will given
  **/
  function confirm_payment($post = array())
  {

    $mollie = new Mollie_API_Client;
    $mollie->setApiKey($this->apikey);
    $transaction_id = $_POST['id'];

    $authentication = array(
      'authorized' => false,
      'error_message' => '',
      'failed' => true,
      'declined' => false,
      'transaction_id' => $transaction_id,
    );

    $payment  = $mollie->payments->get($transaction_id);
    $entry_id = $payment->metadata->order_id;
    
    //need to relaunch cart, otherwise the inventory will not be updated
    $this->relaunch_cart_snapshot($entry_id);

    if ($payment->isPaid() == TRUE)
    {
      /*
       * At this point you'd probably want to start the process of delivering the product to the customer.
       */
      $authentication['authorized'] = true;
      $authentication['declined'] = false;
      $authentication['failed'] = false;
      $authentication['error_message'] = '';
    }
    elseif ($payment->isOpen() == FALSE)
    {
      /*
       * The payment isn't paid and isn't open anymore. We can assume it was aborted.
       */
      $authentication['error_message'] = 'Excuses, er ging iets mis of u heeft uw betaling geannuleerd. Probeer aub. opnieuw.';
      $authentication['failed'] = true;
    }
    if(isset($entry_id) && $this->plugin_settings('webhook_report_email') != '') {
      mail($this->plugin_settings('webhook_report_email'), 'Mollie report for order: '.$entry_id, 'Transaction ID: '.$transaction_id.' Status: '.$payment->status);
    }
    //update called order
    $this->gateway_order_update($authentication, $entry_id, '');

  }
  
} // END CLASS

/* End of file Cartthrob_mollie_ideal.php */
/* Location: ./system/modules/payment_gateways/Cartthrob_mollie_ideal.php */
