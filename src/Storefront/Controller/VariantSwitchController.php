<?php declare(strict_types=1);

namespace SasVariantSwitch\Storefront\Controller;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Exception\LineItemNotFoundException;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\Content\Product\SalesChannel\FindVariant\FindProductVariantRoute;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use SasVariantSwitch\Storefront\Event\ProductBoxLoadedEvent;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class VariantSwitchController extends StorefrontController
{
    private FindProductVariantRoute $combinationFinder;
    private SalesChannelRepository $productRepository;
    private CartService $cartService;
    private LineItemFactoryRegistry $lineItemFactory;
    private EventDispatcherInterface $dispatcher;

    public function __construct(
        FindProductVariantRoute $combinationFinder,
        SalesChannelRepository $productRepository,
        CartService $cartService,
        LineItemFactoryRegistry $lineItemFactory,
        EventDispatcherInterface $dispatcher
    ) {
        $this->combinationFinder = $combinationFinder;
        $this->productRepository = $productRepository;
        $this->cartService = $cartService;
        $this->dispatcher = $dispatcher;
        $this->lineItemFactory = $lineItemFactory;
    }

    #[Route(path: '/sas/line-item/switch-variant/{id}', name: 'sas.frontend.lineItem.variant.switch', defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function switchLineItemVariant(Cart $cart, string $id, Request $request, SalesChannelContext $context): Response
    {
        try {
            $options = $request->get('options');

            if ($options === null) {
                throw new \InvalidArgumentException('options field is required');
            }

            $productId = $request->get('parentId');

            if ($productId === null) {
                throw new \InvalidArgumentException('parentId field is required');
            }

            if (!$cart->has($id)) {
                throw new LineItemNotFoundException($id);
            }

            $lineItem = $cart->get($id);

            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                throw new \InvalidArgumentException('Line item is not a product');
            }

            $switchedOption = $request->query->has('switched') ? (string) $request->query->get('switched') : null;

            try {
                $redirect = $this->combinationFinder->load($productId, new Request(
                    [
                        'switchedGroup' => $switchedOption,
                        'options' => $options,
                    ]
                ), $context);

                $productId = $redirect->getFoundCombination()->getVariantId();
            } catch (ProductNotFoundException $productNotFoundException) {
                //nth

                return new Response();
            }

            $lineItems = $cart->getLineItems();
            $newLineItems = new LineItemCollection();

            /** @var LineItem $lineItem */
            foreach ($lineItems as $lineItem) {
                if ($lineItem->getId() === $id) {
                    $item = [
                        'id' => $productId,
                        'referencedId' => $productId,
                        'stackable' => $lineItem->isStackable(),
                        'removable' => $lineItem->isRemovable(),
                        'quantity' => $lineItem->getQuantity(),
                        'type' => LineItem::PRODUCT_LINE_ITEM_TYPE
                    ];

                    $newLineItem = $this->lineItemFactory->create($item, $context);

                    if ($newLineItems->has($productId)) {
                        $newLineItem->setQuantity($lineItem->getQuantity() + $newLineItems->get($productId)->getQuantity());
                    }

                    $newLineItems->set($productId, $newLineItem);
                    continue;
                }

                $newLineItems->add($lineItem);
            }

            $cart->setLineItems($newLineItems);
            $cart = $this->cartService->recalculate($cart, $context);

            if (!$this->traceErrors($cart)) {
                $this->addFlash(self::SUCCESS, $this->trans('checkout.cartUpdateSuccess'));
            }
        } catch (\Exception $exception) {
            $this->addFlash(self::DANGER, $this->trans('error.message-default'));
        }

        return $this->createActionResponse($request);
    }

    #[Route(path: '/sas/switch-variant/{productId}', name: 'sas.frontend.variant.switch', defaults: ['XmlHttpRequest' => true], methods: ['GET'])]
    public function switchVariant(string $productId, Request $request, SalesChannelContext $context): Response
    {
        $switchedOption = $request->query->has('switched') ? (string) $request->query->get('switched') : null;

        $cardType = $request->query->has('cardType') ? (string) $request->query->get('cardType') : 'standard';

        $options = (string) $request->query->get('options');
        $newOptions = $options !== '' ? json_decode($options, true) : [];

        try {
            $redirect = $this->combinationFinder->load($productId, new Request(
                [
                    'switchedGroup' => $switchedOption,
                    'options' => $newOptions,
                ]
            ), $context);

            $productId = $redirect->getFoundCombination()->getVariantId();
        } catch (ProductNotFoundException $productNotFoundException) {
            //nth

            return new Response();
        }

        $criteria = (new Criteria([$productId]))
            ->addAssociation('manufacturer.media')
            ->addAssociation('options.group')
            ->addAssociation('properties.group')
            ->addAssociation('mainCategories.category')
            ->addAssociation('media');

        $criteria->addExtension('sortings', new ProductSortingCollection());

        $result = $this->productRepository->search($criteria, $context);

        $product = $result->get($productId);

        $this->dispatcher->dispatch(
            new ProductBoxLoadedEvent($request, $product, $context)
        );

        return $this->renderStorefront("@Storefront/storefront/component/product/card/box-$cardType.html.twig", [
            'product' => $product,
            'layout' => $cardType
        ]);
    }

    private function traceErrors(Cart $cart): bool
    {
        if ($cart->getErrors()->count() <= 0) {
            return false;
        }

        $this->addCartErrors($cart, function (Error $error) {
            return $error->isPersistent();
        });

        return true;
    }
}
