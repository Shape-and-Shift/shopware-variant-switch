<?php declare(strict_types=1);

namespace SasVariantSwitch\Subscriber;

use SasVariantSwitch\SasVariantSwitch;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use SasVariantSwitch\Storefront\Page\ProductListingConfigurationLoader;

class CartPageLoadedSubscriber implements EventSubscriberInterface
{
    private ProductListingConfigurationLoader $listingConfigurationLoader;
    private SalesChannelRepositoryInterface $productRepository;
    private SystemConfigService $systemConfigService;

    public function __construct(
        SalesChannelRepositoryInterface $productRepository,
        ProductListingConfigurationLoader $listingConfigurationLoader,
        SystemConfigService $systemConfigService
    ) {
        $this->listingConfigurationLoader = $listingConfigurationLoader;
        $this->productRepository = $productRepository;
        $this->systemConfigService = $systemConfigService;
    }

    public static function getSubscribedEvents()
    {
        return [
            OffcanvasCartPageLoadedEvent::class => [
                ['onOffCanvasCartPageLoaded', 201],
            ],
            CheckoutCartPageLoadedEvent::class => [
                ['onCheckoutCartPageLoaded', 201],
            ],
            CheckoutConfirmPageLoadedEvent::class => [
                ['onCheckoutConfirmPageLoaded', 201],
            ]
        ];
    }

    public function onOffCanvasCartPageLoaded(OffcanvasCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!$this->systemConfigService->getBool(SasVariantSwitch::SHOW_ON_OFFCANVAS_CART, $context->getSalesChannelId())) {
            return;
        }

        $lineItems = $event->getPage()->getCart()->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        if ($lineItems->count() === 0) {
            return;
        }

        $context = $event->getSalesChannelContext();

        $this->addLineItemPropertyGroups($lineItems, $context);
    }

    public function onCheckoutCartPageLoaded(CheckoutCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!$this->systemConfigService->getBool(SasVariantSwitch::SHOW_ON_CART_PAGE, $context->getSalesChannelId())) {
            return;
        }

        $lineItems = $event->getPage()->getCart()->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        if ($lineItems->count() === 0) {
            return;
        }

        $this->addLineItemPropertyGroups($lineItems, $context);
    }

    public function onCheckoutConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!$this->systemConfigService->getBool(SasVariantSwitch::SHOW_ON_CHECKOUT_CONFIRM_PAGE, $context->getSalesChannelId())) {
            return;
        }

        $lineItems = $event->getPage()->getCart()->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        if ($lineItems->count() === 0) {
            return;
        }

        $this->addLineItemPropertyGroups($lineItems, $context);
    }

    private function addLineItemPropertyGroups(LineItemCollection $lineItems, SalesChannelContext $context): void
    {
        $productIds = $lineItems->getReferenceIds();

        $criteria = new Criteria($productIds);

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        if ($products->count() <= 0) {
            return;
        }

        $this->listingConfigurationLoader->loadListing($products, $context);

        /** @var SalesChannelProductEntity $product */
        foreach ($products as $product) {
            if ($product->getExtension('groups') !== null) {
                $lineItem = $lineItems->get($product->getId());

                if (null !== $lineItem) {
                    $lineItem->addExtension('groups', $product->getExtension('groups'));
                    $lineItem->setPayloadValue('parentId', $product->getParentId());
                    $lineItem->setPayloadValue('optionIds', $product->getOptionIds());
                }
            }
        }
    }
}
