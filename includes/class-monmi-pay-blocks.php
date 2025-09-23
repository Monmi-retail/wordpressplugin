<?php
/**
 * Monmi Pay WooCommerce Blocks integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
    return;
}

class Monmi_Pay_Blocks_Payment_Method extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    /** @var Monmi_Pay_Plugin */
    private $plugin;

    /** @var Monmi_Pay_Gateway|null */
    private $gateway = null;

    public function __construct( Monmi_Pay_Plugin $plugin ) {
        $this->plugin = $plugin;
        $this->name   = Monmi_Pay_Gateway::GATEWAY_ID;
    }

    public function initialize(): void {
        Monmi_Pay_Plugin::instance()->register_scripts();
    }

    public function is_active(): bool {
        $gateway = $this->get_gateway();

        return $gateway ? $gateway->is_available() : false;
    }

    public function get_payment_method_script_handles(): array {
        return [ 'monmi-blocks' ];
    }

    public function get_payment_method_data(): array {
        $gateway = $this->get_gateway();

        return [
            'title'            => $gateway ? $gateway->get_title() : __( 'Monmi Pay', 'monmi-pay' ),
            'description'      => $gateway ? $gateway->get_description() : '',
            'createPaymentUrl' => esc_url_raw( rest_url( 'monmi-pay/v1/create-payment' ) ),
            'nonce'            => wp_create_nonce( 'wp_rest' ),
            'environment'      => get_option( Monmi_Pay_Plugin::OPTION_ENVIRONMENT, 'development' ),
            'session'          => Monmi_Pay_Gateway::get_localized_session_seed(),
            'i18n'             => $this->get_i18n_strings(),
            'supports'         => $this->get_gateway_supports( $gateway ),
        ];
    }

    private function get_i18n_strings(): array {
        return [
            'genericError'    => __( 'Unable to process your payment at this time. Please try again.', 'monmi-pay' ),
            'sdkMissing'      => __( 'Payment library still loading. Please wait a moment and try again.', 'monmi-pay' ),
            'creatingPayment' => __( 'Creating your Monmi payment session...', 'monmi-pay' ),
            'paymentFailed'   => __( 'Monmi could not authorise your payment. Please try again.', 'monmi-pay' ),
            'paymentSuccess'  => __( 'Payment authorised. Finalising your order...', 'monmi-pay' ),
        ];
    }

    private function get_gateway_supports( ?Monmi_Pay_Gateway $gateway ): array {
        $features = [];

        if ( $gateway && is_array( $gateway->supports ) ) {
            $features = array_values( array_unique( $gateway->supports ) );
        }

        if ( empty( $features ) ) {
            $features = [ 'products' ];
        }

        return [
            'features' => $features,
        ];
    }

    private function get_gateway(): ?Monmi_Pay_Gateway {
        if ( $this->gateway instanceof Monmi_Pay_Gateway ) {
            return $this->gateway;
        }

        if ( ! class_exists( 'Monmi_Pay_Gateway' ) || ! function_exists( 'WC' ) ) {
            return null;
        }

        $gateways = WC()->payment_gateways();
        if ( ! $gateways || ! method_exists( $gateways, 'payment_gateways' ) ) {
            return null;
        }

        $registered = $gateways->payment_gateways();
        if ( isset( $registered[ Monmi_Pay_Gateway::GATEWAY_ID ] ) && $registered[ Monmi_Pay_Gateway::GATEWAY_ID ] instanceof Monmi_Pay_Gateway ) {
            $this->gateway = $registered[ Monmi_Pay_Gateway::GATEWAY_ID ];
            return $this->gateway;
        }

        $available = $gateways->get_available_payment_gateways();
        if ( isset( $available[ Monmi_Pay_Gateway::GATEWAY_ID ] ) && $available[ Monmi_Pay_Gateway::GATEWAY_ID ] instanceof Monmi_Pay_Gateway ) {
            $this->gateway = $available[ Monmi_Pay_Gateway::GATEWAY_ID ];
            return $this->gateway;
        }

        return null;
    }
}
