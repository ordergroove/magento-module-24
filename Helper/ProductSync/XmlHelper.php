<?php

namespace Ordergroove\Subscription\Helper\ProductSync;

use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\ProductFactory;
use Magento\Eav\Model\Config;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\ResourceModel\Iterator;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\StoreRepository;
use Ordergroove\Subscription\Logger\ProductSync\Error\Logger as ErrorLogger;
use Ordergroove\Subscription\Logger\ProductSync\Info\Logger;
use Ordergroove\Subscription\Model\Logging\OrdergrooveLoggingFactory;
use Magento\InventorySales\Model\ResourceModel\GetAssignedStockIdForWebsite;
use Magento\Inventory\Model\Source\Command\GetSourcesAssignedToStockOrderedByPriority;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\EntityManager\MetadataPool;


class SimpleXMLElementExtended extends \SimpleXMLElement {

    /**
     * https://stackoverflow.com/a/20511976
     * Adds a child with $value inside CDATA
     * @param unknown $name
     * @param unknown $value
     */
    public function addChildWithCDATA($name, $value = NULL) {
      $new_child = $this->addChild($name);
  
      if ($new_child !== NULL) {
        $node = dom_import_simplexml($new_child);
        $no   = $node->ownerDocument;
        $node->appendChild($no->createCDATASection($value));
      }
  
      return $new_child;
    }
  }

/**
 * Class XmlHelper
 * @package Ordergroove\Subscription\Helper\ProductSync
 */
class XmlHelper
{
    /**
     * @var StoreRepository
     */
    private $storeRepository;
    /**
     * @var ProductFactory
     */
    private $productFactory;
    /**
     * @var Iterator
     */
    private $iterator;
    /**
     * @var Config
     */
    private $eavConfig;
    /**
     * @var \SimpleXMLElement
     */
    private $xml;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var ErrorLogger
     */
    private $errorLogger;
    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var UrlBuilder
     */
    private $imageHelper;
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var OrdergrooveLoggingFactory
     */
    protected $ordergrooveLoggingFactory;

    /**
     * @var GetAssignedStockIdForWebsite
     */
    protected $getAssignedStock;

    /**
     * @var GetSourcesAssignedToStockOrderedByPriority
     */
    protected $sourcesAssignedToStock;

    /**
     * @var WebsiteRepositoryInterface
     */
    protected $websiteRepo;
    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * XmlHelper constructor.
     * @param StoreRepository $storeRepository
     * @param StoreManagerInterface $storeManager
     * @param ProductFactory $productFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param Config $eavConfig
     * @param Iterator $iterator
     * @param ErrorLogger $errorLogger
     * @param Logger $logger
     * @param UrlBuilder $imageHelper
     * @param OrdergrooveLoggingFactory $ordergrooveLoggingFactory
     * @param GetAssignedStockIdForWebsite $getAssignedStock
     * @param GetSourcesAssignedToStockOrderedByPriority $sourcesAssignedToStock
     * @param WebsiteRepositoryInterface $websiteRepo
     * @param MetadataPool @metadataPool
     */
    public function __construct(
        StoreRepository $storeRepository,
        StoreManagerInterface $storeManager,
        ProductFactory $productFactory,
        ScopeConfigInterface $scopeConfig,
        Config $eavConfig,
        Iterator $iterator,
        ErrorLogger $errorLogger,
        Logger $logger,
        UrlBuilder $imageHelper,
        OrdergrooveLoggingFactory $ordergrooveLoggingFactory,
        GetAssignedStockIdForWebsite $getAssignedStock,
        GetSourcesAssignedToStockOrderedByPriority $sourcesAssignedToStock,
        WebsiteRepositoryInterface $websiteRepo,
        MetadataPool $metadataPool
    ) {
        $this->storeRepository = $storeRepository;
        $this->productFactory = $productFactory;
        $this->iterator = $iterator;
        $this->eavConfig = $eavConfig;
        $this->storeManager = $storeManager;
        $this->errorLogger = $errorLogger;
        $this->logger = $logger;
        $this->imageHelper = $imageHelper;
        $this->scopeConfig = $scopeConfig;
        $this->ordergrooveLoggingFactory = $ordergrooveLoggingFactory;
        $this->getAssignedStock = $getAssignedStock;
        $this->sourcesAssignedToStock = $sourcesAssignedToStock;
        $this->websiteRepo = $websiteRepo;
        $this->metadataPool = $metadataPool;
    }

    /**
     * Helper to get all website ids where the OG module is enabled.
     * @return array
     */
    public function getWebsiteIds()
    {
        $ids = [];
        $stores = $this->storeRepository->getList();
        foreach ($stores as $store) {
            $siteId = $store->getWebsiteId();
            if ($this->scopeConfig
                    ->getValue('ordergroove_subscription/general/enable', ScopeInterface::SCOPE_WEBSITES, $siteId)
                == "1") {
                $ids[] = $siteId;
            }
        }

        return $ids;
    }

    /**
     * Helper to get xml for all products of a specified website
     * @param $websiteId
     * @return \SimpleXMLElement
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function createWebsiteProductsXml($websiteId)
    {
        $metadata = $this->metadataPool->getMetadata(ProductInterface::class);
        $linkField = $metadata->getLinkField();
        try {
            $this->logger->info("In createWebsiteProductsXml for: ".$websiteId);
            $this->xml = new SimpleXMLElementExtended('<products/>');
            $collection = $this->productFactory->create()->getCollection();

            $entityType = $collection->getEntity()->getType();

            $collection->addWebsiteFilter($websiteId);
            $collection->addAttributeToFilter('type_id', ['in' => [Type::TYPE_SIMPLE, Type::TYPE_BUNDLE]]);
            $collection->setFlag('has_stock_status_filter', true);
            if ($this->scopeConfig->getValue('ordergroove_subscription/general/enable_msi', ScopeInterface::SCOPE_WEBSITES, $websiteId)) {
                $stockId = $this->getAssignedStock->execute($this->websiteRepo->getById($websiteId)->getCode());
                $sourceCodeArray = $this->getSourceCodeArray($stockId);
                $collection->joinField(
                    'qty',
                    'inventory_source_item',
                    'quantity',
                    'sku=sku',
                    array('source_code' => $sourceCodeArray),
                    'left'
                )->joinTable('inventory_source_item', 'sku=sku', ['stock_status' => new \Zend_Db_Expr('group_concat(`inventory_source_item`.status)')], array('source_code' => $sourceCodeArray));
            } else {
                $collection->joinField(
                    'qty',
                    'cataloginventory_stock_item',
                    'qty',
                    'product_id=entity_id',
                    '{{table}}.stock_id=1',
                    'left'
                )->joinTable('cataloginventory_stock_item', 'product_id=entity_id', ['stock_status' => 'is_in_stock']);
            }
            $collection->addAttributeToSelect('stock_status')
                ->joinField(
                    'name',
                    'catalog_product_entity_varchar',
                    'value',
                    $linkField.'='.$linkField,
                    '{{table}}.attribute_id=' . $this->eavConfig->getAttribute($entityType, 'name')->getId(),
                    'left'
                )
                ->joinField(
                    'price',
                    'catalog_product_entity_decimal',
                    'value',
                    $linkField.'='.$linkField,
                    '{{table}}.attribute_id=' . $this->eavConfig->getAttribute($entityType, 'price')->getId(),
                    'left'
                )
                ->joinTable(
                    'catalog_product_entity_media_gallery_value',
                    $linkField.'='.$linkField,
                    ['image_value_id' => 'value_id', 'image_position' => 'position'],
                    '{{table}}.position=1'
                )
                ->joinField(
                    'image',
                    'catalog_product_entity_media_gallery',
                    'value',
                    'value_id=image_value_id',
                    '{{table}}.attribute_id=' . $this->eavConfig->getAttribute($entityType, 'media_gallery')->getId(),
                    'left'
                );

            if ($this->scopeConfig->getValue('ordergroove_subscription/general/enable_msi', ScopeInterface::SCOPE_WEBSITES, $websiteId)) {
                $this->iterator->walk($collection->getSelect()->group('sku'), [[$this, 'processProductIntoXml']], ['websiteId' => $websiteId]);
            } else {
                $this->iterator->walk($collection->getSelect(), [[$this, 'processProductIntoXml']], ['websiteId' => $websiteId]);
            }

            return $this->xml;
        } catch (\Exception $e) {
            $this->errorLogger
                ->error("Error while generating product XML for website ID: " . $websiteId, ['exception' => $e]);
            $ordergrooveLogging = $this->ordergrooveLoggingFactory->create();
            $ordergrooveLogging->addData([
                "log_date" => time(),
                "file_path" => $e->getFile() . " on Line " . $e->getLine(),
                "error_message" => $e->getMessage()
            ])->save();
        }
    }

    /**
     * Callback for Iterator->walk
     * @param $args
     */
    public function processProductIntoXml($args)
    {
        $productData = $args['row'];
        $symbols = [
            "search" => ['&trade;', '&reg;', '&copy;'],
            "replace" => ['™', '®', '©']
            ];
        $this->logger->info("Starting XML node generation for product: ".$productData['entity_id']);

        //Image Position determines precedence of product image. We only want #1
        if ($productData['image_position'] !== "1") {
            $this->logger->info("Skipping product row image_position equal to :".$productData['image_position']);
            return;
        }
        $productXml = $this->xml->addChild('product');
        $productXml->addChildWithCDATA('name', str_replace($symbols["search"], $symbols["replace"], $productData['name']));
        $productXml->addChild('product_id', $productData['entity_id']);
        $productXml->addChild('sku', $productData['sku']);
        $productXml->addChild('price', $productData['price']);
        // check if value is null to prevent error
        if ($productData['image']) {
            $productXml->addChild('image_url', $this->generateImageUrlFromRelativePath($productData['image']));
        } else {
            $productXml->addChild('image_url', '');
        }
        if (str_contains($productData['stock_status'], '1')) {
            $productXml->addChild('in_stock', 1);
        } else {
            $productXml->addChild('in_stock', 0);
        }
        $productXml->addChild('details_url', $this->generateProductUrlFromId($productData['entity_id'],$args['websiteId']));

        $this->logger->info("Generated XML node for product: ".$productData['entity_id']);
    }

    /**
     * Generates default product url
     * @param $productId
     * @param $websiteId
     * @return string
     */
    public function generateProductUrlFromId($productId, $websiteId)
    {
        $stores = $this->storeManager->getWebsite($websiteId)->getStores();
        foreach ($stores as $store) {
            $baseUrl = $this->storeManager->getStore($store)->getBaseUrl(UrlInterface::URL_TYPE_WEB);
            return str_replace("http://", "https://", $baseUrl) . 'catalog/product/view/id/' . $productId;
        }
        $baseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        return str_replace("http://", "https://", $baseUrl) . 'catalog/product/view/id/' . $productId;
    }

    public function generateImageUrlFromRelativePath($image)
    {
        $baseUrl = $this->imageHelper->getUrl($image, Area::AREA_FRONTEND);
        $newBaseUrl = str_replace("http://", "https://", $baseUrl);
        $cachePos = strpos($newBaseUrl, "/cache");
        if (!$cachePos) {
            return $newBaseUrl;
        }
        $finalBaseUrl = substr_replace($newBaseUrl, "", $cachePos, strpos($newBaseUrl, $image) - $cachePos);
        return $finalBaseUrl;
    }

    /**
     * @param $stockId
     * @return array
     */
    public function getSourceCodeArray($stockId)
    {
        $sourceArray = [];
        foreach ($this->sourcesAssignedToStock->execute($stockId) as $source) {
            array_push($sourceArray, $source->getData()['source_code']);
        }
        return $sourceArray;
    }
}
