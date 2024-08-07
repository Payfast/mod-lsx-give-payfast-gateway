<?php
/**
 * An extension of the Give-Recurring-Gateway Class
 *
 * @package   give-payfast
 * @author    LightSpeed
 * @license   GPL-3.0+
 * @link
 * @copyright 2024 LightSpeed Team
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Give_Recurring_Gateway' ) ) {
	return;
}

global $give_recurring_payfast;

/**
 * Class Give_Recurring_Payfast
 */
class Give_Recurring_Payfast extends Give_Recurring_Gateway {

	/**
	 * Setup gateway ID and possibly load API libraries.
	 *
	 * @access public
	 * @since  1.0
	 * @return void
	 */
	public function init() {
		$this->id = 'payfast';

		// create as pending.
		$this->offsite = true;

		// Cancellation action.
		add_action( 'give_recurring_cancel_payfast_subscription', array( $this, 'cancel' ), 10, 2 );
		add_action( 'give_subscription_cancelled', array( $this, 'cancel' ), 11, 2 );

		// Validate payfast periods.
		add_action( 'save_post', array( $this, 'validate_recurring_period' ) );
	}

	/**
	 * Creates subscription payment profiles and sets the IDs so they can be stored.
	 *
	 * @access public
	 * @since  1.0
	 */
	public function create_payment_profiles() {
		// Creates a payment profile and then sets the profile ID.
		$this->subscriptions['profile_id'] = 'payfast-' . $this->purchase_data['purchase_key'];

	}

	/**
	 * Validate Payfast Recurring Donation Period
	 *
	 * @description: Additional server side validation for Standard recurring
	 *
	 * @param int $form_id
	 *
	 * @return mixed
	 */
	function validate_recurring_period( $form_id = 0 ) {

		global $post;
		$recurring_option = $_REQUEST['_give_recurring'] ?? 'no';
		$set_or_multi     = $_REQUEST['_give_price_option'] ?? '';

		// Sanity Checks.
		if ( ! class_exists( 'Give_Recurring' ) ) {
			return $form_id;
		}
		if ( 'no' == $recurring_option ) {
			return $form_id;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || isset( $_REQUEST['bulk_edit'] ) ) {
			return $form_id;
		}
		if ( 'revision' == isset( $post->post_type ) && $post->post_type ) {
			return $form_id;
		}
		if ( 'give_forms' != isset( $post->post_type ) || $post->post_type ) {
			return $form_id;
		}
		if ( ! current_user_can( 'edit_give_forms', $form_id ) ) {
			return $form_id;
		}

		// Is this gateway active.
		if ( ! give_is_gateway_active( $this->id ) ) {
			return $form_id;
		}

		$message = __( 'Payfast Only allows for Monthly and Yearly recurring donations. Please revise your selection.', 'give-recurring' );

		$this->setOrMulti( $set_or_multi, $recurring_option, $message, $form_id );

		return $form_id;

	}

	/**
	 * Determines if the subscription can be cancelled
	 *
	 * @access public
	 * @return bool
	 */
	public function can_cancel( $ret, $subscription ) {
		$ret = false;
		if ( 'active' === $subscription->status ) {
			$ret = true;
		}
		return $ret;
	}

	/**
	 * Contacts Payfast and "Cancels" a subscription
	 *
	 * @access public
	 * @return bool
	 */
	public function cancel( $subscription_id, $subscription ) {
		$give_options = give_get_settings();

		if ( isset( $subscription->gateway ) && 'payfast' !== $subscription->gateway ) {
			return false;
		}

		// pass_phrase - must be set on the merchant account for recurring billing.
		$pass_phrase = $give_options['payfast_pass_phrase'];
		if ( isset( $give_options['payfast_pass_phrase'] ) && ! empty( $give_options['payfast_pass_phrase'] ) ) {
			$pass_phrase = trim( $give_options['payfast_pass_phrase'] );
		}

		// array of the data that will be sent to the API for use in the signature generation
		// amount, item_name, & item_description must be added here when performing an update call.
		$hash_array = array(
			'merchant-id' => $give_options['payfast_customer_id'],
			'version'     => 'v1',
			'timestamp'   => date( 'Y-m-d' ) . 'T' . date( 'H:i:s' ),
		);

		// $pf_data
		$pf_data = $hash_array;

		// construct variables.
		foreach ( $pf_data as $key => $val ) {
			$pf_data[ $key ] = stripslashes( trim( $val ) );
		}

		// check if a pass_phrase has been set - must be set.
		if ( isset( $pass_phrase ) ) {
			$pf_data['passphrase'] = stripslashes( trim( $pass_phrase ) );
		}

		// sort the array by key, alphabetically.
		ksort( $pf_data );

		// normalise the array into a parameter string.
		$pf_param_string = '';
		foreach ( $pf_data as $key => $val ) {
			$pf_param_string .= $key . '=' . urlencode( $val ) . '&';
		}

		// remove the last '&' from the parameter string.
		$pf_param_string = substr( $pf_param_string, 0, -1 );

		// hash and push the signature.
		$signature = md5( $pf_param_string );

		// payload array - required for update call (body values are amount, frequency, date).
		$payload = array(); // used for CURLOPT_POSTFIELDS.

		// set up the url.
		$url = 'https://api.payfast.co.za/subscriptions/' . $subscription->profile_id . '/cancel';
		if ( give_is_test_mode() ) {
			$url .= '?testing=true';
		}

		// Set up the headers.
		$headers = array(
			'version'     => 'v1',
			'merchant-id' => $give_options['payfast_customer_id'],
			'signature'   => $signature,
			'timestamp'   => $hash_array['timestamp'],
		);

		// Set up the arguments.
		$args = array(
			'method'    => 'PUT',
			'timeout'   => 60,
			'headers'   => $headers,
			'body'      => http_build_query( $payload ),
			'sslverify' => true,
		);

		// Make the request.
		$response = wp_remote_request( $url, $args );

		// Check for errors.
		if ( is_wp_error( $response ) ) {
			return false;
		}

		// Decode the response body.
		$data = json_decode( wp_remote_retrieve_body( $response ) );

		// Check the response code.
		if ( isset( $data->code ) && $data->code === '200' ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Creates payment and redirects to Payfast
	 *
	 * @access public
	 * @since  1.0
	 * @return void
	 */
	public function complete_signup() {
		$subscription = new Give_Subscription( $this->subscriptions['profile_id'], true );
		payfast_process_payment( $this->purchase_data, $subscription );
	}

	/**
	 * @param $set_or_multi
	 * @param $recurring_option
	 * @param $message
	 * @param int              $form_id
	 *
	 * @return void
	 */
	public function setOrMulti( $set_or_multi, $recurring_option, $message, int $form_id ): void {
		if ( $set_or_multi == 'yes_admin' && $recurring_option == 'multi' ) {
			$prices = $_REQUEST['_give_donation_levels'] ?? array( '' );
			foreach ( $prices as $price_id => $price ) {
				$period = $price['_give_period'] ?? 0;

				if ( in_array( $period, array( 'day', 'week' ) ) ) {
					wp_die(
						esc_html( $message ),
						esc_html__( 'Error', 'give-recurring' ),
						array(
							'response' => 400,
						)
					);
				}
			}
		} elseif ( Give_Recurring()->is_recurring( $form_id ) ) {
			$period = $_REQUEST['_give_period'] ?? 0;

			if ( in_array( $period, array( 'day', 'week' ) ) ) {
				wp_die(
					esc_html( $message ),
					esc_html__( 'Error', 'give-recurring' ),
					array(
						'response' => 400,
					)
				);
			}
		}
	}
}
