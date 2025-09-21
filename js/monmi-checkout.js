(function ($) {
    'use strict';

    if (typeof window.MonmiPayData === 'undefined') {
        if (window.console) {
            console.warn('MonmiPayData not found. Ensure wp_localize_script is executed.');
        }
        return;
    }

    const DATA = window.MonmiPayData;

    const MonmiCheckout = {
        gatewayId: DATA.gatewayId,
        form: null,
        cardContainer: null,
        errorContainer: null,
        tokenInput: null,
        codeInput: null,
        statusInput: null,
        payloadInput: null,
        paymentMethods: null,
        paymentToken: null,
        paymentData: null,
        cardInstance: null,
        allowSubmit: false,
        loadingSession: false,

        init() {
            this.form = $('form.checkout');
            this.cardContainer = $('#monmi-card-element');
            this.errorContainer = $('#monmi-card-errors');
            this.tokenInput = $('#monmi_payment_token');
            this.codeInput = $('#monmi_payment_code');
            this.statusInput = $('#monmi_payment_status');
            this.payloadInput = $('#monmi_payment_payload');

            if (DATA.session) {
                this.applySessionSeed(DATA.session);
            }

            $(document.body).on('updated_checkout', () => {
                this.refresh();
            });

            $(document.body).on('payment_method_selected', () => {
                this.refresh();
            });

            if (this.form.length) {
                this.form.on('submit', (event) => {
                    this.onFormSubmit(event);
                });
            }

            this.refresh();
        },

        refresh() {
            this.paymentMethods = $('input[name="payment_method"]');
            this.paymentMethods.off('change.monmi').on('change.monmi', (event) => {
                this.onPaymentMethodChanged($(event.target).val());
            });

            const selected = this.getSelectedMethod();
            if (selected === this.gatewayId) {
                if (this.cardContainer.length) {
                    this.cardContainer.show();
                }
                this.ensurePaymentSession(!this.paymentToken);
            } else {
                this.teardown();
            }
        },

        getSelectedMethod() {
            const selected = $('input[name="payment_method"]:checked');
            return selected.length ? selected.val() : null;
        },

        onPaymentMethodChanged(method) {
            if (method === this.gatewayId) {
                if (this.cardContainer.length) {
                    this.cardContainer.show();
                }
                this.ensurePaymentSession(true);
            } else {
                this.teardown();
            }
        },

        teardown() {
            if (this.cardContainer && this.cardContainer.length) {
                this.cardContainer.hide();
            }
            this.clearError();
            this.resetHiddenFields();
        },

        resetHiddenFields() {
            this.paymentToken = null;
            this.paymentData = null;

            if (this.tokenInput.length) {
                this.tokenInput.val('');
            }

            if (this.codeInput.length) {
                this.codeInput.val('');
            }

            if (this.statusInput.length) {
                this.statusInput.val('');
            }

            if (this.payloadInput.length) {
                this.payloadInput.val('');
            }
        },
        applySessionSeed(seed) {
            if (!seed || typeof seed !== 'object') {
                return;
            }

            if (seed.token) {
                this.paymentToken = seed.token;
            }

            if (seed.data && typeof seed.data === 'object') {
                this.paymentData = seed.data;
            }

            if (seed.code) {
                this.paymentData = this.paymentData || {};
                this.paymentData.code = seed.code;
            }

            if (seed.status) {
                this.paymentData = this.paymentData || {};
                this.paymentData.status = seed.status;
            }

            if (seed.status && this.statusInput.length) {
                this.statusInput.val(seed.status);
            }

            this.populateHiddenFields();
        }


        ensurePaymentSession(forceUpdate) {
            if (!this.cardContainer.length) {
                return;
            }

            if (this.paymentToken && !forceUpdate) {
                this.populateHiddenFields();
                this.renderCardElement();
                return;
            }

            if (this.loadingSession) {
                return;
            }

            this.loadingSession = true;
            this.showStatus(DATA.i18n.creatingPayment || 'Creating your Monmi payment session...');
            this.resetHiddenFields();

            const payload = {
                billing: this.collectAddress('billing'),
                shipping: this.collectAddress('shipping'),
            };

            fetch(DATA.createPaymentUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': DATA.nonce,
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            })
                .then(async (response) => {
                    if (!response.ok) {
                        let message = response.statusText || DATA.i18n.genericError || 'Unable to create Monmi payment.';
                        try {
                            const errorBody = await response.json();
                            if (errorBody && errorBody.message) {
                                message = errorBody.message;
                            }
                        } catch (parseError) {
                            // Ignore JSON parse failures, fall back to default message.
                        }
                        throw new Error(message);
                    }
                    return response.json();
                })
                .then((data) => {
                    if (!data || !data.token) {
                        throw new Error(DATA.i18n.genericError);
                    }

                    this.paymentToken = data.token;
                    this.paymentData = data.payment || {};

                    if (data.code) {
                        this.paymentData.code = data.code;
                    }

                    if (data.status) {
                        this.paymentData.status = data.status;
                    }
                    this.populateHiddenFields();
                    this.clearError();
                    this.renderCardElement();
                })
                .catch((error) => {
                    this.showError(error && error.message ? error.message : DATA.i18n.genericError);
                })
                .finally(() => {
                    this.loadingSession = false;
                });
        },

        populateHiddenFields() {
            if (this.tokenInput.length) {
                this.tokenInput.val(this.paymentToken || '');
            }

            if (this.statusInput.length) {
                const status = this.paymentData && this.paymentData.status ? this.paymentData.status : (this.paymentToken ? 'pending' : '');
                this.statusInput.val(status);
            }

            if (this.codeInput.length) {
                const code = this.paymentData && this.paymentData.code ? this.paymentData.code : '';
                this.codeInput.val(code);
            }

            if (this.payloadInput.length) {
                try {
                    this.payloadInput.val(this.paymentData ? JSON.stringify(this.paymentData) : '');
                } catch (e) {
                    this.payloadInput.val('');
                }
            }
        },

        renderCardElement() {
            if (!this.paymentToken || !this.cardContainer.length) {
                return;
            }

            if (typeof window.MonmiPay === 'undefined') {
                this.showError(DATA.i18n.sdkMissing || 'Payment library still loading. Please wait a moment and try again.');
                return;
            }

            if (this.cardInstance && typeof this.cardInstance.update === 'function') {
                this.cardInstance.update({ token: this.paymentToken });
                return;
            }

            if (typeof window.MonmiPay.render === 'function') {
                this.cardInstance = window.MonmiPay.render({
                    token: this.paymentToken,
                    element: '#monmi-card-element',
                });
                return;
            }

            if (typeof window.MonmiPay.init === 'function') {
                this.cardInstance = window.MonmiPay.init({
                    token: this.paymentToken,
                    mount: '#monmi-card-element',
                });
                return;
            }

            if (window.console) {
                console.warn('Monmi Pay SDK does not expose a render/init API. Complete the integration inside js/monmi-checkout.js.');
            }
        },

        onFormSubmit(event) {
            if (!this.shouldHandleSubmission()) {
                return;
            }

            if (this.allowSubmit) {
                this.allowSubmit = false;
                return;
            }

            if (!this.paymentToken) {
                this.showError(DATA.i18n.genericError);
                event.preventDefault();
                return;
            }

            this.populateHiddenFields();

            if (!this.cardInstance || typeof this.cardInstance.confirm !== 'function') {
                return;
            }

            event.preventDefault();
            this.setProcessing(true);
            this.clearError();
            if (this.statusInput.length) {
                this.statusInput.val('processing');
            }

            Promise.resolve(this.cardInstance.confirm())
                .then((result) => {
                    if (result && result.error) {
                        throw result.error;
                    }

                    this.handlePaymentResult(result);
                    this.allowSubmit = true;
                    this.form.trigger('submit');
                })
                .catch((error) => {
                    if (this.statusInput.length) {
                        this.statusInput.val('failed');
                    }
                    this.showError(error && error.message ? error.message : (DATA.i18n.paymentFailed || DATA.i18n.genericError));
                })
                .finally(() => {
                    this.setProcessing(false);
                });
        },

        handlePaymentResult(result) {
            if (result && result.token) {
                this.paymentToken = result.token;
            }

            if (result && (result.payment || result.payload)) {
                this.paymentData = result.payment || result.payload;
            }

            if (!this.paymentData) {
                this.paymentData = {};
            }

            if (result && result.code) {
                this.paymentData.code = result.code;
            }

            this.paymentData.status = 'success';

            this.populateHiddenFields();

            if (result && result.code) {
                this.codeInput.val(result.code);
            }

            if (this.statusInput.length) {
                this.statusInput.val('success');
            }

            this.showStatus(DATA.i18n.paymentSuccess || 'Payment authorised. Finalising your order...');
        },

        shouldHandleSubmission() {
            if (!this.form.length) {
                return false;
            }

            return this.getSelectedMethod() === this.gatewayId;
        },

        collectAddress(prefix) {
            const fields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'];
            const data = {};

            fields.forEach((field) => {
                const input = this.form.find(`[name="${prefix}_${field}"]`);
                if (input.length) {
                    data[field] = input.val();
                }
            });

            return data;
        },

        showError(message) {
            if (!this.errorContainer.length) {
                return;
            }

            this.errorContainer.text(message).show();
        },

        showStatus(message) {
            if (!this.errorContainer.length) {
                return;
            }

            this.errorContainer.text(message).show();
        },

        clearError() {
            if (!this.errorContainer.length) {
                return;
            }

            this.errorContainer.text('').hide();
        },

        setProcessing(isProcessing) {
            if (!this.form.length) {
                return;
            }

            if (isProcessing) {
                this.form.addClass('processing');
            } else {
                this.form.removeClass('processing');
            }
        },
    };

    $(function () {
        MonmiCheckout.init();
    });
})(jQuery);
