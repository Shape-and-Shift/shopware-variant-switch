import DomAccess from 'src/helper/dom-access.helper';
import Iterator from 'src/helper/iterator.helper';
import OffCanvasCartPlugin from 'src/plugin/offcanvas-cart/offcanvas-cart.plugin';

export default class OffCanvasCartSwitchOptionPlugin extends OffCanvasCartPlugin {

    _registerEvents() {
        super._registerEvents();

        this.switchOptions = {
            switchOptionTriggerSelector: '.js-offcanvas-cart-switch-option'
        }

        this._registerSwitchOptionEvents();
    }

    _registerSwitchOptionEvents() {
        const selects = DomAccess.querySelectorAll(document, this.switchOptions.switchOptionTriggerSelector, false);

        if (selects) {
            Iterator.iterate(selects, select => select.addEventListener('change', this._onSwitchLineItemOption.bind(this)));
        }
    }

    _onSwitchLineItemOption(event) {
        const select = event.target;
        const form = select.closest('form');
        const switchedInput = form.querySelector('.form-switched');
        switchedInput.value = event.target.id;

        const selector = this.options.cartItemSelector;

        this.$emitter.publish('onSwitchLineItemOption');

        this._fireRequest(form, selector);
    }
}
