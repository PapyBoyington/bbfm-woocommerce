<?php


class wc_BBFM
{

    var $BBFM_API_URL = 'https://byteball-for-merchants.com/api/ask_payment.php';
    var $CASHBACK_API_URL = 'https://byte.money/new_purchase';

    function __construct()
    {
    
        global $BBFM_WC_Logger;// our WC_Logger instance
    
        if( get_option( 'wc_bbfm_enable' ) ){
        
            // Add this Gateway to WooCommerce
            add_filter('woocommerce_payment_gateways', array( $this, 'woocommerce_add_bbfm_gateway' ) );
            
            // Display payment unit on the order details table
            add_action('woocommerce_order_details_after_order_table', array( $this, 'display_unit_byteball_explorer_link'), 10, 1);
            
            // Display byteball payment button on thankyou page
            add_action('wp_enqueue_scripts', array( $this, 'load_paybutton_scripts') );
            add_action('woocommerce_thankyou', array( $this, 'render_paybutton') );
            add_filter('woocommerce_get_formatted_order_total', array( $this, 'display_total_in_byteball' ), 10, 2 );
                        
            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_bbfm', array(
                $this,
                'handle_bbfm_notifications'
            ));
            
            
            // cashback program
            if( get_option( 'wc_bbfm_partner' ) ){
            
                // add customer byteball address to checkout fields
                add_filter( 'woocommerce_checkout_fields' , array( $this, 'add_customer_byteball_address_field') );
                
                // validate byteball address
                add_action('woocommerce_checkout_process',  array( $this, 'check_order_byteball_address' ) );
                
                // Display field value on the order edit page
                add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_admin_order_byteball_address'), 10, 1 );

                // Make casback api request as soon as an order is set to completed
                add_action( 'woocommerce_order_status_completed', array( $this, 'make_cashback_api_request'), 10, 1 );

            }

        }
        
        // add *our* plugin settings page
        add_action('admin_menu', array( $this, 'add_page' ) );
        add_action('admin_init', array( $this, 'settings_api_init' ) );
        
        // unset standard woocommerce settings page
        add_filter( 'woocommerce_get_sections_checkout', array( $this, 'wcslider_all_settings' ) );
        
        

        
        
    }
    
    
    function make_cashback_api_request( $order_id ) {
        
        // log
        $this->bbfm_logger( 'debug', "order_id $order_id completed" );
        
        $partner = get_option( 'wc_bbfm_partner' );
        
        if( ! $partner ){
            return;
        }
        
        $address = get_post_meta( $order_id, '_billing_byteball_address', true);
        
        if( ! $address ){
            return;
        }
        

        /*
        * set up request
        */
        
        $wc_order = new WC_Order($order_id);
        
        if( $wc_order->get_payment_method() == 'bbfm' ){
            $currency = 'GBYTE';
            $currency_amount = get_post_meta( $order_id, '_wc_bbfm_received_amount', true);
        }else{
            $currency = get_woocommerce_currency();
            $currency_amount = $wc_order->get_total();
        }
        
        $data = array(
            'partner' => $partner,
            'partner_key' => get_option( 'wc_bbfm_partner_key' ),
            'customer' => $wc_order->get_customer_id(),
            'order_id' => $order_id,
            'description' => 'woocommerce sale',// ?
            'merchant' => $partner, // ?
            'address' => $address,
            'currency' => $currency,
            'currency_amount' => $currency_amount,
            'partner_cashback_percentage' => get_option( 'wc_bbfm_partner_cashback_percent', '0' ),
            'purchase_unit' => get_post_meta( $order_id, '_wc_bbfm_receive_unit', true),
        );


        $CURLOPT_URL = $this->CASHBACK_API_URL;


        /*
        * make request
        */
    
        $curl_request_result = $this->process_curl_request( $CURLOPT_URL, $data, 'post' );
            
        // log
        $this->bbfm_logger( 'debug', "curl_request_result : " . wc_print_r( $curl_request_result, true ) );
    
    
        /*
        * handle result
        */
    
        $curl_result = $curl_request_result[ 'result' ];
        $returned_body_json = $curl_request_result[ 'body' ];
        
        $returned_body = json_decode( $returned_body_json, true );
    

        if( $curl_result == 'nok' ){
    
            $error_message = isset( $curl_request_result[ 'error_message' ] ) ? $curl_request_result[ 'error_message' ] : '';
            
            $wc_order->add_order_note(__('Curl error on cashback api request', 'bbfm-woocommerce') .' : ' . $error_message );
    
        }else if( $curl_result == 'ok' ){
    
            if( $returned_body[ 'result' ] == 'error' ){
        
                $cashback_error_msg = $returned_body[ 'error' ];
                
                $wc_order->add_order_note(__('Error returned on cashback api request', 'bbfm-woocommerce') .' : ' . $cashback_error_msg );
            
            }else if( $returned_body[ 'result' ] == 'ok' ){
        
                $cashback_amount = $returned_body[ 'cashback_amount' ];
                $cashback_unit = $returned_body[ 'unit' ];
                
                $wc_order->add_order_note(__('Cashback request has been processed', 'bbfm-woocommerce') .' -  cashback byteball amount : ' . $cashback_amount . ' - cashback unit : ' . $cashback_unit );
            
            }else{
    
                $wc_order->add_order_note( 'Unhandled returned result on cashback API request : ' . $returned_body[ 'result' ] );
                        
            }
        
        }else{
    
            $wc_order->add_order_note( 'Unhandled curl_result on cashback API request : ' . $curl_result );
                
        }

    }
    
    
    function load_paybutton_scripts() {

        $order_id = isset($_REQUEST["order-received"]) ? $_REQUEST["order-received"] : "";
                
        if( $order_id ){ 

            $data = $this -> set_payment_data( $order_id );
            
            $this->bbfm_logger( 'debug', 'paybutton data : ' . wc_print_r( $data, true ) );
            
            wp_enqueue_style(
                'bbfm_style',
                WC_BBFM_PATH . 'bbfm-style.css'
            );
 
            wp_enqueue_script(
                'bbfm_payment_button',
                'https://byteball-for-merchants.com/api/payment-button-dev.js'
            );
    
            wp_localize_script( 'bbfm_payment_button', 'bbfm_params', $data );
            
        }
     
    }
    
        
    function render_paybutton( $order_id ){
    
        $order = new WC_Order($order_id);
        
        if( $order->get_payment_method() == 'bbfm' ){

            echo "<div id=\"bbfm_container\"></div>";// and just let the bbfm's magic js operate !
            
            echo "<div id=\"bbfm_post_paybutton_info\"><p>After you made the payment, it will take about 5-15 minutes for it to be confirmed by the network.<br>Our server will process it, you do not need to keep this page open.</p></div>";
            
            //test
//             $this->bbfm_logger( 'emergency', 'render paybutton' );
//             $this->bbfm_logger( 'alert', 'render paybutton' );
//             $this->bbfm_logger( 'critical', 'render paybutton' );
//             $this->bbfm_logger( 'error', 'render paybutton' );
//             $this->bbfm_logger( 'warning', 'render paybutton' );
//             $this->bbfm_logger( 'notice', 'render paybutton' );
//             $this->bbfm_logger( 'informational', 'render paybutton' ); // seems not working
//             $this->bbfm_logger( 'debug', 'render paybutton' );

            $this->bbfm_logger( 'debug', 'render paybutton' );
            
        }
    
    }
    
    
    function display_total_in_byteball( $formatted_total, $order ){
    
        if( $order->get_payment_method() == 'bbfm' and get_post_meta( $order->id, '_wc_bbfm_amount_BB_asked', true ) ){
        
            $formatted_total = $formatted_total . ' ( ' . number_format_i18n( get_post_meta( $order->id, '_wc_bbfm_amount_BB_asked', true ) ) . ' bytes )';
            
        }
        
        return $formatted_total;
    
    }
    
    
    function wcslider_all_settings( $settings ) {
        
        unset( $settings[ 'bbfm' ] );
        
        return $settings;
    
    }
    
    
    function check_order_byteball_address() {
    
        if ( isset( $_POST['billing_byteball_address'] ) and $_POST['billing_byteball_address'] ){
        
            if( ! $this->check_BB_address( $_POST['billing_byteball_address'] ) ){
                wc_add_notice( 'Invalid byteball address' , 'error' );
            }
            
        }else if( isset( $_POST[ 'payment_method' ] ) and $_POST[ 'payment_method' ] == 'bbfm' ){
        
            wc_add_notice( "You must enter your <strong>byteball cashback address</strong> if you pay with byteball ( otherwise you won't receive any cashback ! )." , 'error' );
            
            
        }
    }
    
    
    function add_invalid_class_to_byteball_address_field( $fields ){
    
        $fields['billing']['billing_byteball_address']['required'] = true;
        
        $fields['billing']['billing_byteball_address']['label'] .= '<abbr class="required" title="requis">*</abbr>';
        
        return $fields;
        
    }
    
    
    function add_customer_byteball_address_field( $fields ) {
         $fields['billing']['billing_byteball_address'] = array(
            'label'     => __("<strong>* Byteball address *</strong><br>Enter here your byteball address if you want to enjoy your cashback !<br>If you don't have any byteball address yet, just go to <a href='https://byteball.org/#download' target='_blank'>byteball.org</a> and download the byteball wallet.", 'woocommerce'),
            'placeholder'   => _x('your cashback byteball address', 'placeholder', 'woocommerce'),
            'required'  => false,
            'class'     => array('form-row-wide'),
            'clear'     => true,
         );

         return $fields;
    }
    
    
    function display_admin_order_byteball_address( $order ){
        echo '<p><strong>'.__('Cashback byteball address').':</strong> ' . get_post_meta( $order->get_id(), '_billing_byteball_address', true ) . '</p>';
    }
    
    
    /**********************
     * plugin settings page
     */
    
    
    function add_page(){
    
        add_submenu_page( 'woocommerce', 'Byteball payments', 'Byteball payments', 'manage_options', 'byteball_options', array( $this, 'show_options' ) );
    
    }
    
    
    function show_options(){
        ?>
        <div class="wrap">
        <h2>Byteball Payments Options</h2>
        <?php settings_errors(); ?>
        <form method="POST" action="options.php">
        <?php
    
        settings_fields( 'byteball_options' );	//pass slug name of page, also referred
                                                //to in Settings API as option group name
        do_settings_sections( 'byteball_options' ); 	//pass slug name of page
    
        submit_button();
    
        ?>
        </form>
        </div>
        <?php

    }
    
    
    function settings_api_init() {
    
    
        // add_settings_section
    
        add_settings_section(
            'general_section',
            'General options',
            '',
            'byteball_options'
        );
        
        add_settings_section(
            'cashback_prg_section',
            'Byteball Cashback Program',
            array( $this, 'cashback_section_callback'),
            'byteball_options'
        );
        
        add_settings_section(
            'logs_section',
            'Logs',
            array( $this, 'logs_section_callback'),
            'byteball_options'
        );
        
        
        // add_settings_field
        
        add_settings_field(
            'wc_bbfm_enable',
            'Enable byteball payments',
            array( $this, 'wc_bbfm_enable_callback' ),
            'byteball_options',
            'general_section'
        );
    
        add_settings_field(
            'wc_bbfm_merchant_email',
            'Email notification address',
            array( $this, 'wc_bbfm_merchant_email_callback' ),
            'byteball_options',
            'general_section'
        );
        
        add_settings_field(
            'wc_bbfm_byteball_address',
            'Byteball address',
            array( $this, 'wc_bbfm_byteball_address_callback' ),
            'byteball_options',
            'general_section'
        );
        
        add_settings_field(
            'wc_bbfm_partner',
            'Partner name',
            array( $this, 'wc_bbfm_partner_callback' ),
            'byteball_options',
            'cashback_prg_section'
        );
        
        add_settings_field(
            'wc_bbfm_partner_key',
            'Partner key',
            array( $this, 'wc_bbfm_partner_key_callback' ),
            'byteball_options',
            'cashback_prg_section'
        );
        
        add_settings_field(
            'wc_bbfm_partner_cashback_percent',
            'Partner percent',
            array( $this, 'wc_bbfm_partner_cashback_percent_callback' ),
            'byteball_options',
            'cashback_prg_section'
        );
        
        add_settings_field(
            'wc_bbfm_log_enable',
            'Enable logs',
            array( $this, 'wc_bbfm_log_enable_callback' ),
            'byteball_options',
            'logs_section'
        );
        
//         add_settings_field(
//             'wc_bbfm_log_level',
//             'Logs level',
//             array( $this, 'wc_bbfm_log_level_callback' ),
//             'byteball_options',
//             'logs_section'
//         );
        
        
        // register_setting
        
        register_setting( 'byteball_options', 'wc_bbfm_enable' );
        register_setting( 'byteball_options', 'wc_bbfm_merchant_email', array( $this, 'wc_bbfm_merchant_email_validate') );
        register_setting( 'byteball_options', 'wc_bbfm_byteball_address', array( $this, 'wc_bbfm_byteball_address_validate' ) );
        register_setting( 'byteball_options', 'wc_bbfm_partner', array( $this, 'wc_bbfm_partner_validate' ) );
        register_setting( 'byteball_options', 'wc_bbfm_partner_key', array( $this, 'wc_bbfm_partner_key_validate' ) );
        register_setting( 'byteball_options', 'wc_bbfm_partner_cashback_percent', array( $this, 'wc_bbfm_partner_cashback_percent_validate' ) );
        register_setting( 'byteball_options', 'wc_bbfm_log_enable' );
//         register_setting( 'byteball_options', 'wc_bbfm_log_level' );
        
     }
     
    
    /*
     * callbacks
     */
    
    function cashback_section_callback(){
               
        echo "<p>Let your customers benefit from a <strong>minimum 10% cashback</strong> for every order completed in your website !</p>";
        echo "<p>The cashback will be automatically sent in byteball to your customer as soon as his order is completed.</p>";
        echo "<p>Contact <a href='mailto:byteball@byteball.org'>the byteball team</a> if you want to be part of the cashback program and get a partner nam and partner key.</p>";
        
    }
    
    function logs_section_callback(){
                    
        echo "<p>For debugging purpose.</p>";
        echo "<p>You can enable here the logs of the plugins actions and select a log level.</p>";
        echo "<p>You will see these logs on the <a href='" . admin_url() . "admin.php?page=wc-status&tab=logs' target='_blank'>Woocommerce status logs page</a>.</p>";
    }
    
    function wc_bbfm_enable_callback() {
        echo '<input name="wc_bbfm_enable" id="wc_bbfm_enable" type="checkbox" value="1"' . checked( get_option( 'wc_bbfm_enable' ) , 1, false ) .'/>';
    }
    
    
    function wc_bbfm_merchant_email_callback() {
        echo '<input size="40" name="wc_bbfm_merchant_email" id="wc_bbfm_merchant_email" type="text" value="' . get_option( 'wc_bbfm_merchant_email' ) . '" /><br>Enter the email address on which you want to be notified of your byteball payments.';
    }
    
    
    function wc_bbfm_byteball_address_callback() {
        echo '<input size="40" name="wc_bbfm_byteball_address" id="wc_bbfm_byteball_address" type="text" value="' . get_option( 'wc_bbfm_byteball_address' ) . '" /><br>Your unique byteball merchant address to receive your payments.';
    }
    
    
    function wc_bbfm_partner_callback() {
        echo '<input size="40" name="wc_bbfm_partner" id="wc_bbfm_partner" type="text" value="' . get_option( 'wc_bbfm_partner' ) . '" />';
    }
    
    
    function wc_bbfm_partner_key_callback() {
        echo '<input size="40" name="wc_bbfm_partner_key" id="wc_bbfm_partner_key" type="text" value="' . get_option( 'wc_bbfm_partner_key' ) . '" />';
    }
    
    
    function wc_bbfm_partner_cashback_percent_callback() {
        echo '<input size="1" maxlength="2" name="wc_bbfm_partner_cashback_percent" id="wc_bbfm_partner_cashback_percent" type="text" value="' . get_option( 'wc_bbfm_partner_cashback_percent' ) . '" />%<br>The percentage of the amount you want to pay to the customer out of your own funds in addition to the regular cashback. Byteball will add the same percentage out of the distribution fund (merchant match). Default it 0. You have to deposit the funds in advance in order to fund this option.';
    }
    
    function wc_bbfm_log_enable_callback() {
        echo '<input name="wc_bbfm_log_enable" id="wc_bbfm_log_enable" type="checkbox" value="1"' . checked( get_option( 'wc_bbfm_log_enable' ) , 1, false ) .'/>';
    }
    
    function wc_bbfm_log_level_callback() {
        ?>
        <select name="wc_bbfm_log_level">
          <option value="emergency" <?php selected(get_option('wc_bbfm_log_level'), "emergency"); ?>>emergency (system is unusable)</option>
          <option value="alert" <?php selected(get_option('wc_bbfm_log_level'), "alert"); ?>>alert (action must be taken immediately)</option>
          <option value="critical" <?php selected(get_option('wc_bbfm_log_level'), "critical"); ?>>critical (critical conditions)</option>
          <option value="error" <?php selected(get_option('wc_bbfm_log_level'), "error"); ?>>error (error conditions)</option>
          <option value="warning" <?php selected(get_option('wc_bbfm_log_level'), "warning"); ?>>warning (warning conditions)</option>
          <option value="notice" <?php selected(get_option('wc_bbfm_log_level'), "notice"); ?>>notice (normal but significant condition)</option>
          <option value="debug" <?php selected(get_option('wc_bbfm_log_level'), "debug"); ?>>debug (debug-level messages)</option>
        </select>
    <?php
    }
    
    
    
    
    /*
     * validations
     */
    
    function wc_bbfm_merchant_email_validate( $email ) {
        
        $output = get_option( 'wc_bbfm_merchant_email' );

        if ( is_email( $email ) or strlen( $email ) == 0 )
            $output = $email;
        else
            add_settings_error( 'byteball_options', 'wc_bbfm_merchant_email', 'You have entered an invalid e-mail address.' );

        return $output;
        
    }
    
    
    function wc_bbfm_byteball_address_validate( $address ) {
        
        $output = get_option( 'wc_bbfm_byteball_address' );

        if ( $this->check_BB_address( $address ) ){
        
            $output = $address;
            
        }else if( strlen( $address) == 0 ){
        
            if( get_option( 'wc_bbfm_enable' ) ){
            
                add_settings_error( 'byteball_options', 'wc_bbfm_byteball_address', 'You must enter your payment address if you enable byball payments.' );
            
            }else{
            
                $output = $address;
            
            }
            
        }else{
        
            add_settings_error( 'byteball_options', 'wc_bbfm_byteball_address', 'You have entered an invalid byteball address.' );
            
        }

        return $output;
        
    }
    
    
    function wc_bbfm_partner_validate( $partner ) {
        
        $output = get_option( 'wc_bbfm_partner' );

        if ( strlen( $partner ) == 0  )
            add_settings_error( 'byteball_options', 'wc_bbfm_merchant_email', "You won't benefit from 20% cashback program if you don't enter your partner info", 'updated' );


        return $partner;
        
    }
    
    function wc_bbfm_partner_key_validate( $key ) {
        
        $output = get_option( 'wc_bbfm_partner_key' );

        if ( strlen( $key ) == 0 and get_option( 'wc_bbfm_partner' ) )
            add_settings_error( 'byteball_options', 'wc_bbfm_merchant_email', 'You must enter your partner key if you have a partner name.' );
        else
             $output = $key;

        return $output;
        
    }
    
    
    function wc_bbfm_partner_cashback_percent_validate( $percent ) {
    
        $max_percent = 40;
        
        $output = get_option( 'wc_bbfm_partner_cashback_percent' );
        
        if ( preg_match('/^\d*$/', $percent) ){
            
            if( $percent > $max_percent ){
                add_settings_error( 'byteball_options', 'wc_bbfm_partner_cashback_percent', "The cashback partner percent cannot be greater than $max_percent%." );
            }else{
                $output = $percent;
            }
            
        }else{
            add_settings_error( 'byteball_options', 'wc_bbfm_partner_cashback_percent', 'The cashback partner percent must be an interger.' );
        }

        return $output;
        
    }
    
    
    /*
     * end of plugin settings page
     **********************/

    
    /*
     * ask BBFM server for a byteball payment address
     */
     
    function ask_payment( $order_id ){
    
        $data = $this -> set_payment_data( $order_id );

        
        $setopt_array = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_URL => $this->BBFM_API_URL,
            CURLOPT_USERAGENT => 'woocommerce-bbfm',
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $data,
        );
        
        
        // logs
        
        $log_msg = "** ask_payment( $order_id ) ***";
        $log_msg .= " setopt_array : " . wc_print_r( $setopt_array, true );
        $this->bbfm_logger( 'debug', $log_msg );

    
        $curl = curl_init();
    
        curl_setopt_array($curl, $setopt_array );
    
        $curl_return = curl_exec($curl);
    
    
        // curl error
        
        if( curl_error($curl) ){
        
            $return_error = $this->return_error( 'Curl error when asking byteball payment address : ' . curl_error($curl) . ' - Code: ' . curl_errno($curl) );
            return $return_error;
            
        }
        
        
        // logs
        
        $log_msg = "** curl_return of ask_payment( $order_id ) ***";
        $log_msg .= " $curl_return";
        $this->bbfm_logger( 'debug', $log_msg );
        
        
        return $curl_return;
    

    }
    
    
    /*
     * handle notif received fom BBFM server
     */
    
    function handle_bbfm_notifications(){
    
        // logs
        $this->bbfm_logger( 'debug', 'notification received : ' . wc_print_r( $_REQUEST, true ) );
                         
        // first read secret key bbfm should be the only one to know
        $secret_key = isset($_REQUEST['secret_key']) ? $_REQUEST['secret_key'] : "";
        
        if( $secret_key ){
        
            $callback_secret = get_option("wc_bbfm_callback_secret");
            
            
            // bad secret_key
            
            if( $callback_secret != $secret_key ){
                $this->bbfm_logger( 'debug', 'notif secret_key not equal to registered callback_secret (' . $callback_secret . ')' );
                wp_die( 'bad secret key', 403 );
            }
            
            
            /*
             * now authentified notif
             */
             
            $order_id = isset($_REQUEST['order_UID']) ? $_REQUEST['order_UID'] : "";
            $result = isset($_REQUEST['result']) ? $_REQUEST['result'] : "";
            $cashback_result = isset($_REQUEST['cashback_result']) ? $_REQUEST['cashback_result'] : "";
            
            
            if( $result ){
            
                /*
                 * order payment status
                 */
            
                $amount_asked_in_currency = $this->sanitize_and_register_input( 'amount_asked_in_currency', $order_id );
                $currency_B_rate = $this->sanitize_and_register_input( 'currency_B_rate', $order_id );
                $received_amount = $this->sanitize_and_register_input( 'received_amount', $order_id );
                $receive_unit = $this->sanitize_and_register_input( 'receive_unit', $order_id );
                $fee = $this->sanitize_and_register_input( 'fee', $order_id );
                $amount_sent = $this->sanitize_and_register_input( 'amount_sent', $order_id );
                $unit = $this->sanitize_and_register_input( 'unit', $order_id );
            
                $result = $this->sanitize_and_register_input( 'result', $order_id );
            
                $wc_order = new WC_Order( $order_id );
                
            
                // result nok
            
                if( $result == 'nok' ){
            
                    $error_msg = $this->sanitize_and_register_input( 'error_msg', $order_id );
            
                    $wc_order->update_status('failed', __($error_msg, 'bbfm-woocommerce'));
                
                    wp_die( 'ok', 200 );
                
                }
                
                
                // result receiving
            
                if( $result == 'receiving' ){
                        
                    $wc_order->update_status('on-hold', __( 'Costumer payment has been received by BBFM server (not yet network confirmed).', 'bbfm-woocommerce' ));                
                    wp_die( 'ok', 200 );
                
                }
                
                
                // result received
            
                if( $result == 'received' ){
                        
                    $wc_order->add_order_note(__('Costumer payment has been confirmed by the network.', 'bbfm-woocommerce'));

                    wp_die( 'ok', 200 );
                
                }

                
                
                // result unconfirmed
            
                if( $result == 'unconfirmed' ){
                        
                    $wc_order->add_order_note(__('Payment has been sent to you but is still waiting for network confirmation.', 'bbfm-woocommerce'));

                    wp_die( 'ok', 200 );
                
                }
            
            
                // result completed
            
                if( $result == 'ok' ){
                
                    // check received_amount (!)
                    if( $received_amount != get_option('_wc_bbfm_amount_BB_asked', true) ){
                    
                        $error_msg = 'Received amount does not match asked amount';
            
                        $wc_order->update_status('failed', __($error_msg, 'bbfm-woocommerce'));
                    
                    }else{
            
                        $wc_order->add_order_note(__('Payment completed', 'bbfm-woocommerce'));
                        
                        $wc_order->payment_complete( $unit );
                        
                    }
                
                    wp_die( 'ok', 200 );
                
                }
            
                $this->bbfm_logger( 'debug', 'not handled result' );
                wp_die( 'not handled result', 200 );
                
                
            }else if( $cashback_result ){
            
                /*
                 * cashback status
                 */
            
                $cashback_result = $this->sanitize_and_register_input( 'cashback_result', $order_id );
                $cashback_error_msg = $this->sanitize_and_register_input( 'cashback_error_msg', $order_id );
                $cashback_amount = $this->sanitize_and_register_input( 'cashback_amount', $order_id );
                $cashback_unit = $this->sanitize_and_register_input( 'cashback_unit', $order_id );
//                 $cashback_notified = $this->sanitize_and_register_input( 'cashback_notified', $order_id );
            
                $wc_order = new WC_Order( $order_id );
                
                    
                // cashback_result ok
            
                if( $cashback_result == 'ok' ){
        
                    $wc_order->add_order_note( __('Cashback successfully processed', 'bbfm-woocommerce') . ". $cashback_amount bytes sent on unit $cashback_unit" );
            
                    wp_die( 'ok', 200 );
                    
                }
                
                
                // cashback_result error
            
                if( $cashback_result == 'error' ){
        
                    $wc_order->add_order_note(__('Error on cashback api request', 'bbfm-woocommerce') . ' : ' . $cashback_error_msg );
            
                    wp_die( 'ok', 200 );
                    
                }
                
                $this->bbfm_logger( 'debug', 'not handled cashback result' );
                wp_die( 'not handled cashback result', 200 );

            }
        
        }
        
        $this->bbfm_logger( 'debug', 'Unauthorized request' );
        wp_die( 'Unauthorized', 401 );
        
        
    }// handle_bbfm_notifications
    
    
    
    function sanitize_and_register_input( $var_name, $order_id ){

        $value = isset($_REQUEST[ $var_name ]) ? $_REQUEST[ $var_name ] : "";

        $sanitized_value = sanitize_text_field( $value );

        update_post_meta( $order_id, '_wc_bbfm_' . $var_name, $sanitized_value );
            
        return $sanitized_value;

    }

    
    
    function set_payment_data( $order_id ){
    
        $order = new WC_Order($order_id);
        
        $data = array(
            'mode' => 'live',
            'mode_notif' => 'POST',
            'order_UID' => $order_id,
            'currency' => get_woocommerce_currency(),
            'merchant_return_url' => WC()->api_request_url('WC_Gateway_BBFM'),
            'amount' => $order->get_total(),
            'merchant_email' => get_option( 'wc_bbfm_merchant_email' ),
            'partner' => get_option( 'wc_bbfm_partner' ),
            'partner_key' => get_option( 'wc_bbfm_partner_key' ),
            'partner_cashback_percentage' => get_option( 'wc_bbfm_partner_cashback_percent' ),
            'customer' => $order->get_customer_id(),
            'description' => 'woocommerce sale',// ?
            'byteball_merchant_address' => get_option( 'wc_bbfm_byteball_address' ),
            'callback_secret' => $this->bbfm_callback_secret(),
            'cashback_address' => get_post_meta( $order_id, '_billing_byteball_address', true),
        );
        
        return $data;
        
    }
    
    
    function bbfm_callback_secret()
    {
        $callback_secret = get_option("wc_bbfm_callback_secret");
        
        if ( !$callback_secret ) {
        
            $callback_secret = sha1(openssl_random_pseudo_bytes(20));
            
            // logs
            $this->bbfm_logger( 'debug', 'new callback_secret created : ' . wc_print_r( $callback_secret, true ) );
            
            update_option("wc_bbfm_callback_secret", $callback_secret);
            
        }
        
        return $callback_secret;
    }
    
    
    function check_BB_address( $address ){

        if( preg_match( "@^[0-9A-Z]{32}$@", $address ) or $address == 'NO-SENDING-ADDRESS-ON-TEST-MODE' ){
            return true;
        }else{
            return false;
        }

    }
    
    
    function return_error( $msg ){

        $return = array(
            'result' => 'nok',
            'error_msg' => $msg,
        );
    
        return json_encode($return);

    }
    
    
    function display_unit_byteball_explorer_link( $order ){
    
        $unit = get_post_meta($order->id, '_wc_bbfm_unit', true);
        
        if( $unit ){
            echo '<p><strong>'.__('Byteball payment unit', 'bbfm-woocommerce').':</strong>  <a href ="' . 'https://explorer.byteball.org/#' . $unit . '" target="blank" title="see unit in byteball explorer" >' . $unit . '</a></p>';
        }
            
    }
    
     
    function woocommerce_add_bbfm_gateway($methods){
    
        // logs
//         $this->bbfm_logger( 'debug', 'woocommerce_add_bbfm_gateway : ' . wc_print_r( $methods, true ) );
        
        $methods[] = 'WC_Gateway_BBFM';
        return $methods;
        
    }
    
    function bbfm_logger( $level, $msg ){
    
        if( get_option('wc_bbfm_log_enable', true) ){
    
            global $BBFM_WC_Logger;
        
            $BBFM_WC_Logger->log( $level, $msg, array( 'source' => WC_BBFM_LOG_SRC ));
            
        }
        
    }
    
    
    function process_curl_request( $CURLOPT_URL, $data, $mode = 'post' ){
    
        // log
        $this->bbfm_logger( 'debug', "\nmake $mode request to $CURLOPT_URL : " . wc_print_r( $data, true ) );


        /*
         * prepare GET notif
         */
     
         if( $mode == 'get' ){

            $CURLOPT_URL = $CURLOPT_URL . '?' . http_build_query( $data );
        
            $setopt_array = array(
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HEADER => false,
                CURLOPT_NOBODY => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_URL => $CURLOPT_URL . '?' . http_build_query( $data ),
                CURLOPT_USERAGENT => 'Byteball for Woocommerce',
            );
        
     
         /*
         * prepare POST notif
         */
     
         }else{
     
            $setopt_array = array(
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HEADER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_USERAGENT => 'Byteball for Woocommerce',
                CURLOPT_POSTFIELDS => http_build_query( $data ),
                CURLOPT_SSL_CIPHER_LIST => 'ecdhe_ecdsa_aes_256_sha',
            );
        
     
         }
      
      
        /*
         * execute curl request
         */
    

        $curl = curl_init();
    
        curl_setopt_array($curl, $setopt_array );
    
        $response = curl_exec($curl);
    
        if( curl_error($curl) ){
        
            // log
            $this->bbfm_logger( 'debug', 'curl_error : ' . curl_error($curl) . ' - Code: ' . curl_errno($curl) );
    
            return array(
                'result' => 'nok',
                'error_message' => curl_error($curl),
                'body' => $response,
            );
        
        }else{
    
            $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
            if( $httpcode == '200' ){

                return array(
                'result' => 'ok',
                'body' => $response,
            );
            
            }else{
                
                // log
                $this->bbfm_logger( 'debug', "curl returned non 200 HTTP on $mode request on $CURLOPT_URL - code : " . $httpcode );
                        
                return array(
                    'result' => 'nok',
                    'error_message' => 'returned non 200 HTTP code : ' . $httpcode,
                    'CURLOPT_URL' => $CURLOPT_URL,// used for failure email notification
                    'body' => $response,
                );
            
            }
            
        
        }


    }
    
}


