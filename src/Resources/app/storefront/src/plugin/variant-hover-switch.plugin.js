import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';
import DomAccess from 'src/helper/dom-access.helper';
import Iterator from 'src/helper/iterator.helper';
import queryString from 'query-string';

export default class VariantHoverSwitchPlugin extends Plugin {
    static options = {
        radioFieldSelector: '.sas-product-configurator-option-input',
        selectFieldSelector: '.sas-product-configurator-select-input',
        urlAttribute: 'data-url',
        cardType: 'standard'
    };

    init() {
        this._httpClient = new HttpClient();
        this._radioFields = DomAccess.querySelectorAll(this.el, this.options.radioFieldSelector, false);
        this._selectFields = DomAccess.querySelectorAll(this.el, this.options.selectFieldSelector, false);

        this._productBox = this.el.closest('.product-box');
        window.variantResponseCached = window.variantResponseCached || {};
        this._hoveringValue = null;

        this._preserveCurrentValues();
        this._registerEvents();
    }

    /**
     * saves the current value on each form element
     * to be able to retrieve it once it has changed
     *
     * @private
     */
    _preserveCurrentValues() {
        if(this._radioFields) {
            Iterator.iterate(this._radioFields, field => {
                if (VariantHoverSwitchPlugin._isFieldSerializable(field)) {
                    if (field.dataset) {
                        field.dataset.variantSwitchValue = field.value;
                    }
                }
            });
        }
    }

    /**
     * register all needed events
     *
     * @private
     */
    _registerEvents() {
        if(this._radioFields) {
            Iterator.iterate(this._radioFields, field => {
                field.addEventListener('change', event => this._onChange(event.target));
                const label = field.parentElement.querySelector('label');

                if (window.sasPreviewVariantOnHover) {
                    label.addEventListener('mouseenter', event => {
                        const input = event.target.parentElement.querySelector('input');
                        this._hoveringValue = input.value;

                        if (input && !input.checked) {
                            setTimeout(() => {
                                if (this._hoveringValue && this._hoveringValue === input.value) {
                                    input.click();
                                }
                            }, 200)
                        }
                    });

                    label.addEventListener('mouseleave', event => {
                        this._hoveringValue = null;
                    });
                }
            });
        }

        if(this._selectFields) {
            Iterator.iterate(this._selectFields, field => {
                field.addEventListener('change', event => this._onChange(event.target));
            });
        }
    }

    /**
     * callback when the form has changed
     *
     * @param element
     * @private
     */
    _onChange(element) {
        const switchedOptionId = this._getSwitchedOptionId(element);
        const selectedOptions = this._getFormValue();
        this._preserveCurrentValues();

        this.$emitter.publish('onChange');

        const query = {
            switched: switchedOptionId,
            options: JSON.stringify(selectedOptions),
            cardType: this.options.cardType
        };

        ElementLoadingIndicatorUtil.create(this.el);

        let url = DomAccess.getAttribute(element, this.options.urlAttribute);

        url = url + '?' + queryString.stringify({ ...query });

        if (window.variantResponseCached[url]) {
            if (this._productBox) {
                this._productBox.outerHTML = window.variantResponseCached[url];
            }

            ElementLoadingIndicatorUtil.remove(this.el);

            window.PluginManager.initializePlugins();

            return;
        }

        this._httpClient.get(url, (response) => {
            window.variantResponseCached[url] = response;
            if (this._productBox) {
                this._productBox.outerHTML = response;
            }
            ElementLoadingIndicatorUtil.remove(this.el);

            window.PluginManager.initializePlugins()
        });
    }

    /**
     * returns the option id of the recently switched field
     *
     * @param field
     * @returns {*}
     * @private
     */
    _getSwitchedOptionId(field) {
        if (!VariantHoverSwitchPlugin._isFieldSerializable(field)) {
            return false;
        }

        return DomAccess.getAttribute(field, 'data-name');
    }

    /**
     * returns the current selected
     * variant options from the form
     *
     * @private
     */
    _getFormValue() {
        const serialized = {};
        if(this._radioFields) {
            Iterator.iterate(this._radioFields, field => {
                if (VariantHoverSwitchPlugin._isFieldSerializable(field)) {
                    if (field.checked) {
                        serialized[DomAccess.getAttribute(field, 'data-name')] = field.value;
                    }
                }
            });
        }

        if(this._selectFields) {
            Iterator.iterate(this._selectFields, field => {
                if (VariantHoverSwitchPlugin._isFieldSerializable(field)) {
                    const selectedOption = [...field.options].find(option => option.selected);
                    serialized[DomAccess.getAttribute(field, 'data-name')] = selectedOption.value;
                }
            });
        }

        return serialized;
    }

    /**
     * checks id the field is a value field
     * and therefore serializable
     *
     * @param field
     * @returns {boolean|*}
     *
     * @private
     */
    static _isFieldSerializable(field) {
        return !field.name || field.disabled || ['file', 'reset', 'submit', 'button'].indexOf(field.type) === -1;
    }

    /**
     * disables all form fields on the form submit
     *
     * @private
     */
    _disableFields() {
        Iterator.iterate(this._radioFields, field => {
            if (field.classList) {
                field.classList.add('disabled', 'disabled');
            }
        });
    }
}
