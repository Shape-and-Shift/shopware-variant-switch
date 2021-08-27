import VariantHoverSwitchPlugin from './plugin/variant-hover-switch.plugin';
import OffCanvasCartSwitchOptionPlugin from "./plugin/offcanvas-cart-switch-option.plugin";

const PluginManager = window.PluginManager;

if (window.sasShowOnProductCard) {
    PluginManager.register('VariantHoverSwitch', VariantHoverSwitchPlugin, '[data-variant-hover-switch]');
}

if (window.sasShowOnOffCanvasCart) {
    PluginManager.override('OffCanvasCart', OffCanvasCartSwitchOptionPlugin, '[data-offcanvas-cart]');
}
