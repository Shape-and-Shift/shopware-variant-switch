<?php declare(strict_types=1);

namespace SasVariantSwitch\Storefront\Page;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Aggregate\ProductConfiguratorSetting\ProductConfiguratorSettingCollection;
use Shopware\Core\Content\Product\Aggregate\ProductConfiguratorSetting\ProductConfiguratorSettingEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\Detail\AvailableCombinationResult;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupDefinition;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\FetchModeHelper;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ProductListingConfigurationLoader
{
    private EntityRepositoryInterface $configuratorRepository;
    private Connection $connection;

    public function __construct(
        EntityRepositoryInterface $configuratorRepository,
        Connection $connection
    ) {
        $this->configuratorRepository = $configuratorRepository;
        $this->connection = $connection;
    }

    public function loadListing(ProductCollection $products, SalesChannelContext $context): void
    {
        $settings = $this->fetchSettings($products, $context->getContext());

        $productIds = array_filter($products->map(function (SalesChannelProductEntity $product) {
            return $product->getParentId() ?? $product->getId();
        }));

        $allCombinations = $this->loadCombinations($productIds, $context->getContext());

        /** @var SalesChannelProductEntity $product */
        foreach ($products as $product) {
            $productSettings = $this->loadSettings(clone $settings);

            if ($product->getConfiguratorSettings() !== null || !$product->getParentId() || empty($productSettings[$product->getParentId()])) {
                $product->addExtension('groups', new PropertyGroupCollection());

                continue;
            }

            $productSetting = $productSettings[$product->getParentId()];

            $groups = $this->sortSettings($productSetting, $product);

            $combinations = $allCombinations[$product->getParentId()];

            $current = $this->buildCurrentOptions($product, $groups);

            foreach ($groups as $group) {
                $options = $group->getOptions();
                if ($options === null) {
                    continue;
                }

                foreach ($options as $option) {
                    $combinable = $this->isCombinable($option, $current, $combinations);
                    if ($combinable === null) {
                        $options->remove($option->getId());

                        continue;
                    }

                    $option->setGroup(null);

                    $option->setCombinable($combinable);
                }

                $group->setOptions($options);
            }

            $product->addExtension('groups', $groups);
        }
    }

    public function loadCombinations(array $productIds, Context $context): array
    {
        $allCombinations = [];

        $query = $this->connection->createQueryBuilder();
        $query->from('product');
        $query->leftJoin('product', 'product', 'parent', 'product.parent_id = parent.id');

        $query->andWhere('product.parent_id IN (:id)');
        $query->andWhere('product.version_id = :versionId');
        $query->andWhere('IFNULL(product.active, parent.active) = :active');
        $query->andWhere('product.option_ids IS NOT NULL');

        $query->setParameter('id', Uuid::fromHexToBytesList($productIds), Connection::PARAM_STR_ARRAY);
        $query->setParameter('versionId', Uuid::fromHexToBytes($context->getVersionId()));
        $query->setParameter('active', true);

        $query->select([
            'LOWER(HEX(product.id))',
            'LOWER(HEX(product.parent_id)) as parent_id',
            'product.option_ids as options',
            'product.product_number as productNumber',
            'product.available',
        ]);

        $combinations = $query->execute()->fetchAll();
        $combinations = FetchModeHelper::groupUnique($combinations);

        foreach ($combinations as $combination) {
            $parentId = $combination['parent_id'];

            if (\array_key_exists($parentId, $allCombinations)) {
                $allCombinations[$parentId][] = $combination;
            } else {
                $allCombinations[$parentId] = [$combination];
            }
        }

        foreach ($allCombinations as $parentId => $groupedCombinations) {
            $result = new AvailableCombinationResult();

            foreach ($groupedCombinations as $combination) {
                $available = (bool) $combination['available'];

                $options = json_decode($combination['options'], true);
                if ($options === false) {
                    continue;
                }

                $result->addCombination($options, $available);
            }

            $allCombinations[$parentId] = $result;
        }

        return $allCombinations;
    }

    private function fetchSettings(ProductCollection $products, Context $context): ProductConfiguratorSettingCollection
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsAnyFilter('productId', $products->map(function (SalesChannelProductEntity $product) {
                return $product->getParentId() ?? $product->getId();
            }))
        );

        $criteria->addAssociation('option.group')
            ->addAssociation('option.media')
            ->addAssociation('media');

        /**
         * @var ProductConfiguratorSettingCollection $settings
         */
        $settings = $this->configuratorRepository
            ->search($criteria, $context)
            ->getEntities();

        if ($settings->count() <= 0) {
            return new ProductConfiguratorSettingCollection();
        }

        return $settings;
    }

    private function loadSettings(ProductConfiguratorSettingCollection $settings): ?array
    {
        $allSettings = [];

        if ($settings->count() <= 0) {
            return null;
        }

        /** @var ProductConfiguratorSettingEntity $setting */
        foreach ($settings as $setting) {
            $productId = $setting->getProductId();

            if (\array_key_exists($productId, $allSettings)) {
                $allSettings[$productId][] = ProductConfiguratorSettingEntity::createFrom($setting);
            } else {
                $allSettings[$productId] = [ProductConfiguratorSettingEntity::createFrom($setting)];
            }
        }

        foreach ($allSettings as $productId => $settings) {
            $groups = [];

            /** @var ProductConfiguratorSettingEntity $setting */
            foreach ($settings as $setting) {
                $option = $setting->getOption();
                if ($option === null) {
                    continue;
                }

                $group = $option->getGroup();
                if ($group === null) {
                    continue;
                }

                $groupId = $group->getId();

                // if (!in_array($groupId, $groupIds)) {
                //    continue;
                // }

                if (isset($groups[$groupId])) {
                    $group = $groups[$groupId];
                }

                $groups[$groupId] = $group;

                if ($group->getOptions() === null) {
                    $group->setOptions(new PropertyGroupOptionCollection());
                }

                $group->getOptions()->add($option);

                $option->setConfiguratorSetting($setting);
            }

            $allSettings[$productId] = $groups;
        }

        return $allSettings;
    }

    private function sortSettings(?array $groups, SalesChannelProductEntity $product): PropertyGroupCollection
    {
        if (!$groups) {
            return new PropertyGroupCollection();
        }

        $sorted = [];
        foreach ($groups as $group) {
            if (!$group) {
                continue;
            }

            if (!$group->getOptions()) {
                $group->setOptions(new PropertyGroupOptionCollection());
            }

            $sorted[$group->getId()] = $group;
        }

        /** @var PropertyGroupEntity $group */
        foreach ($sorted as $group) {
            $group->getOptions()->sort(
                static function (PropertyGroupOptionEntity $a, PropertyGroupOptionEntity $b) use ($group) {
                    if ($a->getConfiguratorSetting()->getPosition() !== $b->getConfiguratorSetting()->getPosition()) {
                        return $a->getConfiguratorSetting()->getPosition() <=> $b->getConfiguratorSetting()->getPosition();
                    }

                    if ($group->getSortingType() === PropertyGroupDefinition::SORTING_TYPE_ALPHANUMERIC) {
                        return strnatcmp($a->getTranslation('name'), $b->getTranslation('name'));
                    }

                    return ($a->getTranslation('position') ?? $a->getPosition() ?? 0) <=> ($b->getTranslation('position') ?? $b->getPosition() ?? 0);
                }
            );
        }

        $collection = new PropertyGroupCollection($sorted);

        // check if product has an individual sorting configuration for property groups
        $config = $product->getConfiguratorGroupConfig();
        if (!$config) {
            $collection->sortByPositions();

            return $collection;
        } else if ($product->getMainVariantId() === null) {
            foreach ($config as $item) {
                if (\array_key_exists('expressionForListings', $item) && $item['expressionForListings'] && $collection->has($item['id'])) {
                    $collection->get($item['id'])->assign([
                        'hideOnListing' => true,
                    ]);
                }
            }
        }

        $sortedGroupIds = array_column($config, 'id');

        // ensure all ids are in the array (but only once)
        $sortedGroupIds = array_unique(array_merge($sortedGroupIds, $collection->getIds()));

        $collection->sortByIdArray($sortedGroupIds);

        return $collection;
    }

    private function isCombinable(
        PropertyGroupOptionEntity $option,
        array $current,
        AvailableCombinationResult $combinations
    ): ?bool {
        unset($current[$option->getGroupId()]);
        $current[] = $option->getId();

        // available with all other current selected options
        if ($combinations->hasCombination($current) && $combinations->isAvailable($current)) {
            return true;
        }

        // available but not with the other current selected options
        if ($combinations->hasOptionId($option->getId())) {
            return false;
        }

        return null;
    }

    private function buildCurrentOptions(SalesChannelProductEntity $product, PropertyGroupCollection $groups): array
    {
        $keyMap = $groups->getOptionIdMap();

        $current = [];
        foreach ($product->getOptionIds() as $optionId) {
            $groupId = $keyMap[$optionId] ?? null;
            if ($groupId === null) {
                continue;
            }

            $current[$groupId] = $optionId;
        }

        return $current;
    }
}
