<?php
/**
 * Monmi Pay WooCommerce gateway.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Monmi_Pay_Gateway extends WC_Payment_Gateway {
    public const GATEWAY_ID = 'monmi_pay';

    /** @var Monmi_Pay_Plugin */
    private $plugin;

    public function __construct() {
        $this->id                 = self::GATEWAY_ID;
        $this->method_title       = __( 'Monmi Pay', 'monmi-pay' );
        $this->method_description = __( 'Accept secure card payments via Monmi.', 'monmi-pay' );
        $this->icon               = '';
        $this->has_fields         = true;
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'Monmi Pay', 'monmi-pay' ) );
        $this->description = $this->get_option( 'description', __( 'Pay securely with your card via Monmi.', 'monmi-pay' ) );

        $this->plugin = Monmi_Pay_Plugin::instance();

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
    }

    /**
     * Initialize gateway settings fields.
     */
    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'     => [
                'title'   => __( 'Enable/Disable', 'monmi-pay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Monmi Pay', 'monmi-pay' ),
                'default' => 'no',
            ],
            'title'       => [
                'title'       => __( 'Title', 'monmi-pay' ),
                'type'        => 'text',
                'description' => __( 'Controls the payment method title the customer sees during checkout.', 'monmi-pay' ),
                'default'     => __( 'Monmi Pay', 'monmi-pay' ),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __( 'Description', 'monmi-pay' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description displayed during checkout.', 'monmi-pay' ),
                'default'     => __( 'Pay securely using Monmi Pay.', 'monmi-pay' ),
                'desc_tip'    => true,
            ],
        ];
    }

    /**
     * Output payment fields on checkout.
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        echo '<div id="monmi-card-element"></div>';
        echo '<div id="monmi-card-errors" class="woocommerce-error" role="alert" style="display:none"></div>';
        echo '<input type="hidden" name="monmi_payment_token" id="monmi_payment_token" value="" />';
        echo '<input type="hidden" name="monmi_payment_code" id="monmi_payment_code" value="" />';
        echo '<input type="hidden" name="monmi_payment_status" id="monmi_payment_status" value="" />';
        echo '<input type="hidden" name="monmi_payment_payload" id="monmi_payment_payload" value="" />';

        wp_nonce_field( 'monmi_process_payment', 'monmi_payment_nonce' );
    }

    /**
     * Validate checkout fields.
     */
    public function validate_fields(): bool {
        if ( empty( $_POST['monmi_payment_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['monmi_payment_nonce'] ) ), 'monmi_process_payment' ) ) {
            wc_add_notice( __( 'Payment validation failed. Please try again.', 'monmi-pay' ), 'error' );
            return false;
        }

        $status = isset( $_POST['monmi_payment_status'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['monmi_payment_status'] ) ) ) : '';
        $token  = isset( $_POST['monmi_payment_token'] ) ? sanitize_text_field( wp_unslash( $_POST['monmi_payment_token'] ) ) : '';

        if ( 'success' !== $status || ! $token ) {
            wc_add_notice( __( 'Monmi payment was not completed. Please try again.', 'monmi-pay' ), 'error' );
            return false;
        }

        return true;
    }

    /**
     * Enqueue checkout assets.
     */
    public function enqueue_scripts(): void {
        if ( ! is_checkout() || ! $this->is_available() ) {
            return;
        }

        if ( ! wp_script_is( 'monmi-pay-sdk', 'registered' ) || ! wp_script_is( 'monmi-pay-checkout', 'registered' ) ) {
            Monmi_Pay_Plugin::instance()->register_scripts();
        }

        wp_enqueue_script( 'monmi-pay-sdk' );
        wp_enqueue_script( 'monmi-pay-checkout' );
        wp_localize_script(
            'monmi-pay-checkout',
            'MonmiPayData',
            [
                'gatewayId'        => $this->id,
                'nonce'            => wp_create_nonce( 'wp_rest' ),
                'createPaymentUrl' => esc_url_raw( rest_url( 'monmi-pay/v1/create-payment' ) ),
                'environment'      => get_option( Monmi_Pay_Plugin::OPTION_ENVIRONMENT, 'development' ),
                'currency'         => get_woocommerce_currency(),
                'session'         => self::get_localized_session_seed(),
                'i18n'             => [
                    'genericError'    => __( 'Unable to process your payment at this time. Please try again.', 'monmi-pay' ),
                    'sdkMissing'      => __( 'Payment library still loading. Please wait a moment and try again.', 'monmi-pay' ),
                    'creatingPayment' => __( 'Creating your Monmi payment session...', 'monmi-pay' ),
                    'paymentFailed'   => __( 'Monmi could not authorise your payment. Please try again.', 'monmi-pay' ),
                    'paymentSuccess'  => __( 'Payment authorised. Finalising your order...', 'monmi-pay' ),
                ],
            ]
        );
    }

    /**
     * Process the payment.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'monmi-pay' ), 'error' );
            return;
        }

        $status = isset( $_POST['monmi_payment_status'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['monmi_payment_status'] ) ) ) : '';
        $token  = isset( $_POST['monmi_payment_token'] ) ? sanitize_text_field( wp_unslash( $_POST['monmi_payment_token'] ) ) : '';
        $code   = isset( $_POST['monmi_payment_code'] ) ? sanitize_text_field( wp_unslash( $_POST['monmi_payment_code'] ) ) : '';
        $payload_raw = isset( $_POST['monmi_payment_payload'] ) ? wp_unslash( $_POST['monmi_payment_payload'] ) : '';

        if ( ! $token ) {
            wc_add_notice( __( 'Unable to finalise Monmi payment. Please try again.', 'monmi-pay' ), 'error' );
            return;
        }

        $order->update_meta_data( '_monmi_payment_token', $token );

        if ( $status ) {
            $order->update_meta_data( '_monmi_payment_status', $status );
        }

        if ( $code ) {
            $order->update_meta_data( '_monmi_payment_code', $code );
            $order->set_transaction_id( $code );
        }

        if ( $payload_raw ) {
            $decoded = json_decode( $payload_raw, true );
            if ( null !== $decoded ) {
                $order->update_meta_data( '_monmi_payment_payload', wp_json_encode( $decoded ) );
                if ( isset( $decoded['partnerTransactionId'] ) ) {
                    $order->update_meta_data( '_monmi_partner_transaction_id', sanitize_text_field( (string) $decoded['partnerTransactionId'] ) );
                }
                if ( isset( $decoded['status'] ) ) {
                    $order->update_meta_data( '_monmi_gateway_status', sanitize_text_field( (string) $decoded['status'] ) );
                }
            } else {
                $order->update_meta_data( '_monmi_payment_payload_raw', sanitize_textarea_field( $payload_raw ) );
            }
        }

        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->empty_cart();
        }

        $order->update_status( 'on-hold', __( 'Awaiting Monmi webhook confirmation.', 'monmi-pay' ) );

        if ( 'success' === $status ) {
            $order->add_order_note( __( 'Monmi authorised payment at checkout. Awaiting webhook confirmation.', 'monmi-pay' ) );
        } else {
            $order->add_order_note( __( 'Monmi payment initiated. Awaiting webhook confirmation.', 'monmi-pay' ) );
        }

        return [
            'result'   => 'success',
            'redirect' => apply_filters(
                'monmi_pay_checkout_redirect',
                $this->get_return_url( $order ),
                $order
            ),
        ];
    }

    /**
     * Display details on thank-you page.
     */

    public static function get_localized_session_seed(): array {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return [];
        }

        $session = WC()->session->get( 'monmi_payment_session', [] );
        if ( empty( $session ) || ! is_array( $session ) ) {
            return [];
        }

        $seed = [
            'token'  => isset( $session['token'] ) ? sanitize_text_field( (string) $session['token'] ) : '',
            'code'   => isset( $session['code'] ) ? sanitize_text_field( (string) $session['code'] ) : '',
            'status' => isset( $session['status'] ) ? sanitize_text_field( (string) $session['status'] ) : '',
        ];

        if ( ! empty( $session['data'] ) ) {
            $seed['data'] = self::sanitize_session_data( $session['data'] );
        }

        return array_filter(
            $seed,
            static function ( $value ) {
                if ( is_array( $value ) ) {
                    return ! empty( $value );
                }

                return '' !== $value;
            }
        );
    }

    private static function sanitize_session_data( $data ) {
        if ( is_array( $data ) ) {
            $sanitized = [];
            foreach ( $data as $key => $value ) {
                $sanitized[ sanitize_key( (string) $key ) ] = self::sanitize_session_data( $value );
            }

            return $sanitized;
        }

        if ( is_scalar( $data ) ) {
            return sanitize_text_field( (string) $data );
        }

        return $data;
    }

    public function thankyou_page( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $code = $order->get_meta( '_monmi_payment_code' );
        if ( ! $code ) {
            $code = $order->get_meta( '_monmi_partner_transaction_id' );
        }

        if ( $code ) {
            echo '<p>' . esc_html__( 'Monmi transaction code:', 'monmi-pay' ) . ' ' . esc_html( $code ) . '</p>';
        }
    }
}
