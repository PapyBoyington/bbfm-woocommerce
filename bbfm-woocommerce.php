<?php
/**
 * Plugin Name: Byteball Payments for Woocommerce
 * Plugin URI: https://byteball-for-merchants.com/
 * Description: Accept Byteballs on your WooCommerce-powered website with Byteball-for-Merchants
 * Version: 0.1
 * Author: Byteball for merchants
 * Author URI: https://byteball-for-merchants.com/
 * License: MIT
 * Text Domain: bbfm-woocommerce
 */
 
 
 
/*****************
* road map :

- internationalization
- byteball "payments report" admin page
- paybutton customizer


*******************/




/*****************

* main features :

- powered by byteball-for-merchants tools
- completelly integrated with byteball cashback program
- multi-currency (Bytes, BTC, EUR, USD)
- anonymous : no api key needed
- very simplified setup
- include a woocommerce integrated optional debugging tool
- compatible with other woocommerce gateways



*******************/

/*

MIT License

Copyright (c) 2017 Byteball-for-merchants.com developers

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );


if ( is_plugin_active( 'woocommerce/woocommerce.php') || class_exists( 'WooCommerce' )) {

    require_once(plugin_dir_path(__FILE__) . 'wc_BBFM_class.php');
    
    define( 'WC_BBFM_PATH', plugin_dir_url( __FILE__ ) );
    

        
    // ww_BBFM class
    $wc_BBFM = new wc_BBFM;
    
    // to save logs in DB (WC doc says to put it in wp-config.php but it is not really a plugin territory)
    if( ! defined( 'WC_LOG_HANDLER' ) ) define( 'WC_LOG_HANDLER', 'WC_Log_Handler_DB' );
    
        
    

    function bbfm_woocommerce_init()
    {
    
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }
        
        /*
         * setup logs
         */

        global $BBFM_WC_Logger;
    
//         $BBFM_WC_Logger = new WC_Logger( null, get_option('wc_bbfm_log_level', true) );
        $BBFM_WC_Logger = new WC_Logger();

        define( 'WC_BBFM_LOG_SRC', 'BB_for_Woo' );
        
    

        /**
         * Byteball Payment Gateway
         */
         
        class WC_Gateway_BBFM extends WC_Payment_Gateway{
        
            public function __construct(){
            
                $this->id   = 'bbfm';
                $this->icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/byteball.png';

                $this->has_fields        = false;
                $this->order_button_text = __('Pay with byteballs', 'bbfm-woocommerce');
                $this->title = 'Byteball';
                
                $this->enable = get_option( 'wc_bbfm_enable' );
                
                if( get_option( 'wc_bbfm_partner' ) ){
                
                    $description = __("Pay with byteballs and earn a <strong>" . (20 + get_option( 'wc_bbfm_partner_cashback_percent' ) * 2) . "% cashback</strong> !", 'bbfm-woocommerce') .'<br>'. __("Powered by ", 'bbfm-woocommerce'). "<a href='https://www.byteball-for-merchants.com/' target='_blank'>byteball-for-merchants.com</a>";
                    
                }else{
                
                    $description = __("Pay with byteballs") . '.<br>' . __("Powered by ", 'bbfm-woocommerce'). "<a href='https://www.byteball-for-merchants.com/' target='_blank'>byteball-for-merchants.com</a>";
                    
                }
                
                $this->description = $description;
                
                add_action('woocommerce_receipt_' . $this->id, array(
                    $this,
                    'receipt_page'
                ));

                
            }
            

            function add_customer_byteball_address_field( $fields ) {
                 $fields['billing']['byteball_address'] = array(
                    'label'     => __('Byteball address', 'bbfm-woocommerce'),
                    'placeholder'   => _x('enter your cashback byteball address', 'placeholder', 'woocommerce'),
                    'required'  => true,
                    'class'     => array('form-row-wide'),
                    'clear'     => true,
                 );

                 return $fields;
            }
    
    
            function display_admin_order_byteball_address( $order ){
                echo '<p><strong>'.__('Cashback byteball address').':</strong> ' . get_post_meta( $order->get_id(), '_byteball_address', true ) . '</p>';
            }

            public function init_form_fields()
            {                
                $this->form_fields = array(
                );
            }



            public function process_payment( $order_id ){
              
                global $woocommerce, $wc_BBFM;

                $order = new WC_Order($order_id);

                $ask_payment_json = $wc_BBFM -> ask_payment( $order_id );

                $ask_payment = json_decode( $ask_payment_json, true );
                
                
                // ask_payment result = nok
                
                if( $ask_payment[ 'result' ] == 'nok' ){
                
                    wc_add_notice( 'Error returned when asking for byteball payment address : ' . $ask_payment[ 'error_msg' ], 'error' );
                    return;
                
                }
                
                // ask_payment result = 'completed'
                
                if( $ask_payment[ 'result' ] == 'completed' ){
                
                    wc_add_notice( 'The payment of this order has already been completed', 'error' );
                    return;
                
                }
                
                // ask_payment result = 'processing'
                
                if( $ask_payment[ 'result' ] == 'processing' ){
                
                    wc_add_notice( 'The payment of this order is already processing...', 'error' );
                    return;
                
                }
                
                // ask_payment result unknown
                if( $ask_payment[ 'result' ] != 'ok' ){
                
                    wc_add_notice( 'Unknown result value when asking for byteball payment address : ' . $ask_payment[ 'result' ], 'error' );
                    return;
                
                }
                
                $BBaddress = $ask_payment[ 'BBaddress' ];
                $amount_BB_asked = $ask_payment[ 'amount_BB_asked' ];
                
                
                 // debug
//                 wc_add_notice( print_r( $ask_payment_json, true) );
//                 wc_add_notice( "order : " . print_r( $order, true) );
//                 wc_add_notice( "_POST : " . print_r( $_POST, true) );
//                 return;
              
              
                  // check $BBaddress
              
                  if (! $BBaddress){
              
                    $error_msg = "Could not generate new payment byteball address. Note to webmaster: Contact us on http://slack.byteball.org/
    channel #byteball_for_merchant";
                    wc_add_notice($error_msg, 'error');
                    return;
                
                  }else if( ! $wc_BBFM -> check_BB_address( $BBaddress ) ){
              
                    $error_msg = "Received invalid payment byteball address. Note to webmaster: Contact us on http://slack.byteball.org/
    channel #byteball_for_merchant";
                    wc_add_notice($error_msg, 'error');
                    return;
              
                  }
              
              
                  // check $amount_BB_asked
              
                  if( ! preg_match( "@^[0-9]{1,12}$@", $amount_BB_asked ) ){
              
                    $error_msg = "Received invalid byteball amount. Note to webmaster: Contact us on http://slack.byteball.org/
    channel #byteball_for_merchant";
                    wc_add_notice($error_msg, 'error');
                    return;
                
                }
            
            
                // register order payment infos
                
                update_post_meta( $order_id, '_wc_bbfm_BBaddress', $BBaddress );
                update_post_meta( $order_id, '_wc_bbfm_amount_BB_asked', $amount_BB_asked );
    

                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url( $order ),

                );
                
            }
                        
        }
                
    }

    add_action('plugins_loaded', 'bbfm_woocommerce_init', 0);
    
}




?>
