<?php declare(strict_types=1);

namespace SasVariantSwitch\Storefront\Event;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

class ProductBoxLoadedEvent extends Event implements ShopwareEvent
{
    private SalesChannelContext $context;
    private Request $request;
    private SalesChannelProductEntity $product;

    public function __construct(Request $request, SalesChannelProductEntity $product, SalesChannelContext $context)
    {
        $this->request = $request;
        $this->context = $context;
        $this->product = $product;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->context;
    }

    public function getContext(): Context
    {
        return $this->context->getContext();
    }

    public function getProduct(): SalesChannelProductEntity
    {
        return $this->product;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
}
