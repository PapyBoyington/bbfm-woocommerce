<?php


/*
 * check inputs to avoid order browsing
 */
 
$check_address = get_post_meta( $order_id, '_wc_bbfm_BBaddress', true );

if( $check_address !== $BBaddress ){
    wp_die( '<pre>Access forbidden</pre>' );
}


/*
 * set payment params
 */

global $wc_bbfm_order_id; // because we are in a function

$wc_bbfm_order_id = $order_id;// not to interfere with existing vars
 
function pw_load_scripts() {

    global $wc_BBFM, $wc_bbfm_order_id;

    $data = $wc_BBFM -> set_payment_data( $wc_bbfm_order_id );
 
	wp_enqueue_script('bbfm_payment_button', 'https://byteball-for-merchants.com/api/payment-button-dev.js');
	
	wp_localize_script( 'bbfm_payment_button', 'bbfm_params', $data );
	 
}

add_action('wp_enqueue_scripts', 'pw_load_scripts');


/*
 * render page
 */

//call the wp head so  you can get most of your wordpress
get_header();
?>

    
<!-- ByteBall payment button -->
<div id="bbfm_container"></div>

<?php

// echo "<p>order_id: $order_id</p>";

//call the wp foooter
get_footer();
?>