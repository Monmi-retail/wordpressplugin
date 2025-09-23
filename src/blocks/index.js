/*
 * Monmi Pay WooCommerce Blocks integration script.
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { createElement, Fragment, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';

const call = ( fn, ...args ) => ( typeof fn === 'function' ? fn( ...args ) : undefined );

const composePaymentData = ( state ) => {
    const payload = state.payload;
    const data = {
        token: state.token || '',
        code: state.code || '',
        status: state.status || '',
    };

    if ( payload ) {
        data.payload = payload;
    }

    data.monmi_payment_token = data.token;
    data.monmi_payment_code = data.code;
    data.monmi_payment_status = data.status;

    if ( payload ) {
        data.monmi_payment_payload = payload;
    }

    return data;
};

const settings = getSetting( 'monmi_pay_data', {} );
const gatewayId = settings.gatewayId || 'monmi_pay';
const supports = settings.supports || { features: [ 'products' ] };
const titleText = decodeEntities( settings.title || __( 'Monmi Pay', 'monmi-pay' ) );
const i18nStrings = settings.i18n || {};

const MonmiPayComponent = ( props ) => {
    const eventRegistration = props.eventRegistration || {};
    const emitResponse = props.emitResponse || {};
    const billingData = props.billingData || props.billing || {};
    const shippingData = props.shippingData || props.shipping || {};
    const isEditor = !! props.isEditor;

    const initialSession = {
        token: settings.session && settings.session.token ? settings.session.token : '',
        code: settings.session && settings.session.code ? settings.session.code : '',
        status: settings.session && settings.session.status ? settings.session.status : '',
        payload: settings.session && settings.session.data ? settings.session.data : null,
    };

    const [ session, setSession ] = useState( initialSession );
    const sessionRef = useRef( session );
    const updateSession = ( updates ) => {
        sessionRef.current = { ...sessionRef.current, ...updates };
        setSession( { ...sessionRef.current } );
    };

    const billingRef = useRef( billingData );
    const shippingRef = useRef( shippingData );
    const containerRef = useRef( null );
    const instanceRef = useRef( null );

    const [ errorMessage, setErrorMessage ] = useState( '' );
    const [ statusMessage, setStatusMessage ] = useState( '' );

    useEffect( () => {
        billingRef.current = billingData || {};
    }, [ billingData ] );

    useEffect( () => {
        shippingRef.current = shippingData || {};
    }, [ shippingData ] );

    const elementId = useMemo( () => 'monmi-pay-block-' + Math.random().toString( 36 ).slice( 2 ), [] );

    const destroyCard = () => {
        if ( instanceRef.current && typeof instanceRef.current.destroy === 'function' ) {
            instanceRef.current.destroy();
        }

        instanceRef.current = null;

        if ( containerRef.current ) {
            containerRef.current.innerHTML = '';
        }
    };

    const mountCard = () => {
        if ( ! containerRef.current || ! sessionRef.current.token ) {
            return;
        }

        const sdk = window.MonmiPay;
        if ( typeof sdk === 'undefined' ) {
            setErrorMessage( i18nStrings.sdkMissing || __( 'Payment library still loading. Please wait a moment and try again.', 'monmi-pay' ) );
            return;
        }

        if ( instanceRef.current && typeof instanceRef.current.update === 'function' ) {
            instanceRef.current.update( { token: sessionRef.current.token } );
            return;
        }

        const target = '#' + elementId;

        if ( typeof sdk.render === 'function' ) {
            instanceRef.current = sdk.render( {
                token: sessionRef.current.token,
                element: target,
            } );
            return;
        }

        if ( typeof sdk.init === 'function' ) {
            instanceRef.current = sdk.init( {
                token: sessionRef.current.token,
                mount: target,
            } );
            return;
        }

        if ( window.console && typeof window.console.warn === 'function' ) {
            window.console.warn( 'Monmi Pay SDK does not expose a supported render/init API.' );
        }
    };

    const ensureSession = ( billing, shipping ) => {
        if ( sessionRef.current.token ) {
            return Promise.resolve();
        }

        setStatusMessage( i18nStrings.creatingPayment || __( 'Creating your Monmi payment session...', 'monmi-pay' ) );
        setErrorMessage( '' );

        const payload = {
            billing: billing || {},
            shipping: shipping || {},
        };

        return window.fetch( settings.createPaymentUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': settings.nonce,
            },
            credentials: 'same-origin',
            body: JSON.stringify( payload ),
        } )
            .then( ( response ) => {
                return response
                    .json()
                    .catch( () => ( {} ) )
                    .then( ( body ) => {
                        if ( ! response.ok ) {
                            const message = body && body.message ? body.message : ( i18nStrings.genericError ? i18nStrings.genericError : __( 'Unable to process your payment at this time. Please try again.', 'monmi-pay' ) );
                            throw new Error( message );
                        }

                        const token = body && body.token ? body.token : '';
                        const status = body && body.status ? body.status : '';
                        const payment = body && body.payment ? body.payment : null;

                        if ( ! token ) {
                            throw new Error( i18nStrings.genericError ? i18nStrings.genericError : __( 'Unable to process your payment at this time. Please try again.', 'monmi-pay' ) );
                        }

                        const updates = { token, status };
                        if ( payment && payment.code && ! sessionRef.current.code ) {
                            updates.code = payment.code;
                        }
                        if ( payment ) {
                            updates.payload = payment;
                        }

                        updateSession( updates );
                        setStatusMessage( '' );
                        mountCard();
                    } );
            } )
            .catch( ( error ) => {
                destroyCard();
                updateSession( { token: '', status: '', payload: null } );
                setStatusMessage( '' );
                const message = error && error.message ? error.message : ( i18nStrings.genericError ? i18nStrings.genericError : __( 'Unable to process your payment at this time. Please try again.', 'monmi-pay' ) );
                setErrorMessage( message );
                throw error;
            } );
    };

    const confirmPayment = () => {
        const instance = instanceRef.current;
        if ( ! instance || typeof instance.confirm !== 'function' ) {
            return Promise.resolve( null );
        }

        return Promise.resolve( instance.confirm() ).then( ( result ) => {
            if ( result && result.error ) {
                throw result.error;
            }

            const updates = {};

            if ( result && result.token ) {
                updates.token = result.token;
            }

            if ( result && result.code ) {
                updates.code = result.code;
            }

            if ( result && ( result.payment || result.payload ) ) {
                updates.payload = result.payment || result.payload;
            }

            if ( Object.keys( updates ).length ) {
                updateSession( updates );
            }

            return result;
        } );
    };

    useEffect( () => {
        if ( session.token ) {
            mountCard();
        } else {
            destroyCard();
        }
    }, [ session.token, elementId ] );

    useEffect( () => {
        const unsubscribeSelected = call( eventRegistration.onPaymentMethodSelected, () => {
            ensureSession( billingRef.current, shippingRef.current ).catch( () => {} );
        } );

        const unsubscribeDeselected = call( eventRegistration.onPaymentMethodDeselected, () => {
            destroyCard();
        } );

        return () => {
            call( unsubscribeSelected );
            call( unsubscribeDeselected );
        };
    }, [ eventRegistration ] );

    useEffect( () => {
        if ( isEditor ) {
            ensureSession( billingRef.current, shippingRef.current ).catch( () => {} );
        }
    }, [ isEditor ] );

    useEffect( () => {
        const unsubscribeProcessing = call( eventRegistration.onPaymentProcessing, () => {
            call( emitResponse.startedProcessingPayment );

            return ensureSession( billingRef.current, shippingRef.current )
                .then( () => confirmPayment() )
                .then( () => {
                    updateSession( { status: 'success' } );
                    setErrorMessage( '' );
                    setStatusMessage( i18nStrings.paymentSuccess ? i18nStrings.paymentSuccess : __( 'Payment authorised. Finalising your order...', 'monmi-pay' ) );

                    const paymentData = composePaymentData( sessionRef.current );
                    call( emitResponse.finishedProcessingPayment, {
                        status: 'success',
                        paymentMethodData: paymentData,
                    } );

                    return {
                        type: 'success',
                        meta: {
                            paymentMethodData: paymentData,
                        },
                    };
                } )
                .catch( ( error ) => {
                    const message = error && error.message ? error.message : ( i18nStrings.paymentFailed ? i18nStrings.paymentFailed : __( 'Monmi could not authorise your payment. Please try again.', 'monmi-pay' ) );
                    setErrorMessage( message );
                    setStatusMessage( '' );
                    updateSession( { status: 'failed' } );

                    call( emitResponse.finishedProcessingPayment, {
                        status: 'error',
                        message,
                    } );

                    return {
                        type: 'error',
                        message,
                    };
                } );
        } );

        return () => {
            call( unsubscribeProcessing );
        };
    }, [ eventRegistration, emitResponse ] );

    useEffect( () => {
        const unsubscribeValidation = call( eventRegistration.onCheckoutValidation, ( args ) => {
            if ( ! args || typeof args.setValidationError !== 'function' ) {
                return;
            }

            if ( ! sessionRef.current.token ) {
                args.setValidationError( gatewayId, {
                    message: i18nStrings.genericError ? i18nStrings.genericError : __( 'Unable to process your payment at this time. Please try again.', 'monmi-pay' ),
                } );
            }
        } );

        return () => {
            call( unsubscribeValidation );
        };
    }, [ eventRegistration ] );

    return createElement(
        Fragment,
        null,
        createElement(
            'div',
            { className: 'monmi-pay-block__container' },
            createElement( 'div', {
                className: 'monmi-pay-block__sdk-target',
                ref: containerRef,
                id: elementId,
            } ),
            statusMessage
                ? createElement(
                    'div',
                    {
                        className: 'wc-block-components-notice-banner wc-block-components-notice-banner--info',
                        role: 'status',
                    },
                    statusMessage
                )
                : null,
            errorMessage
                ? createElement(
                    'div',
                    {
                        className: 'wc-block-components-notice-banner wc-block-components-notice-banner--error',
                        role: 'alert',
                    },
                    errorMessage
                )
                : null
        )
    );
};

if ( gatewayId ) {
    registerPaymentMethod( {
        name: gatewayId,
        label: () => createElement( 'span', null, titleText ),
        canMakePayment: () => true,
        content: MonmiPayComponent,
        edit: MonmiPayComponent,
        supports,
    } );
}
