<?php declare(strict_types=1);

namespace SasVariantSwitch\Subscriber;

use SasVariantSwitch\SasVariantSwitch;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use SasVariantSwitch\Storefront\Event\ProductBoxLoadedEvent;
use SasVariantSwitch\Storefront\Page\ProductListingConfigurationLoader;

class ProductListingResultLoadedSubscriber implements EventSubscriberInterface
{
    private ProductListingConfigurationLoader $listingConfigurationLoader;
    private SystemConfigService $systemConfigService;

    public function __construct(
        ProductListingConfigurationLoader $listingConfigurationLoader,
        SystemConfigService $systemConfigService
    ) {
        $this->listingConfigurationLoader = $listingConfigurationLoader;
        $this->systemConfigService = $systemConfigService;
    }

    public static function getSubscribedEvents()
    {
        return [
            // 'sales_channel.product.loaded' => 'handleProductListingLoadedRequest',
            ProductListingResultEvent::class => [
                ['handleProductListingLoadedRequest', 201],
            ],
            ProductBoxLoadedEvent::class => [
                ['handleProductBoxLoadedRequest', 201],
            ],
        ];
    }

    public function handleProductListingLoadedRequest(ProductListingResultEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!$this->systemConfigService->getBool(SasVariantSwitch::SHOW_ON_PRODUCT_CARD, $context->getSalesChannelId())) {
            return;
        }

        /** @var ProductCollection $entities */
        $entities = $event->getResult()->getEntities();

        $this->listingConfigurationLoader->loadListing($entities, $context);
    }

    public function handleProductBoxLoadedRequest(ProductBoxLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!$this->systemConfigService->getBool(SasVariantSwitch::SHOW_ON_PRODUCT_CARD, $context->getSalesChannelId())) {
            return;
        }

        $this->listingConfigurationLoader->loadListing(new ProductCollection([$event->getProduct()]), $context);
    }
}
