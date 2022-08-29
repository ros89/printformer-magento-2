<?php

namespace Rissc\Printformer\Helper;

use Magento\Catalog\Model\ProductRepository;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Rissc\Printformer\Model\Draft;
use Rissc\Printformer\Model\DraftFactory;
use Rissc\Printformer\Model\Product as PrintformerProduct;
use Rissc\Printformer\Model\ProductFactory;
use Rissc\Printformer\Model\ResourceModel\Product as ResourceProduct;
use Magento\Framework\App\RequestInterface;
use Rissc\Printformer\Helper\Config;
use Rissc\Printformer\Helper\Session;
use Rissc\Printformer\Setup\InstallSchema;
use Magento\Checkout\Model\Cart;
use Magento\Wishlist\Model\Item as WishlistItem;


class Product extends AbstractHelper
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * @var ResourceProduct
     */
    protected $resource;

    /**
     * @var RequestInterface
     */
    protected $_request;

    /**
     * @var ProductRepository
     */
    private ProductRepository $catalogProductRepository;

    /**
     * @var DraftFactory
     */
    private DraftFactory $draftFactory;

    /**
     * @var Config
     */
    private Config $configHelper;
    private \Rissc\Printformer\Helper\Session $sessionHelper;
    private Cart $cart;
    private WishlistItem $wishlistItem;

    /**
     * Product constructor.
     * @param ProductFactory $productFactory
     * @param ResourceProduct $resource
     * @param ResourceConnection $resourceConnection
     * @param Context $context
     * @param RequestInterface $request
     * @param ProductRepository $catalogProductRepository
     * @param DraftFactory $draftFactory
     * @param Config $configHelper
     */
    public function __construct(
        ProductFactory $productFactory,
        ResourceProduct $resource,
        ResourceConnection $resourceConnection,
        Context $context,
        RequestInterface $request,
        ProductRepository $catalogProductRepository,
        DraftFactory $draftFactory,
        Config $configHelper,
        Session $sessionHelper,
        Cart $cart,
        WishlistItem $wishlistItem
    ) {
        $this->productFactory = $productFactory;
        $this->resource = $resource;
        $this->resourceConnection = $resourceConnection;
        $this->_request = $request;
        $this->catalogProductRepository = $catalogProductRepository;
        $this->draftFactory = $draftFactory;
        $this->configHelper = $configHelper;
        $this->sessionHelper = $sessionHelper;
        $this->cart = $cart;
        $this->wishlistItem = $wishlistItem;

        parent::__construct($context);
    }

    /**
     * Fetch-all on db-table *catalog_product_printformer_product  for product-id and possible child-product-ids
     *
     * @param $productId
     * @param int $storeId
     * @param bool $includeDefaultStoreInWhereSelect
     * @param array $childProductIds
     * @return array
     */
    protected function getCatalogProductPrintformerProductsData($productId, int $storeId = 0, bool $includeDefaultStoreInWhereSelect = true, array $childProductIds = [])
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from('catalog_product_printformer_product');

        if ($includeDefaultStoreInWhereSelect && $storeId !== 0){
            $select->where("store_id IN (". 0 . "," . $storeId .")");
        } else {
            $select->where('store_id = ?', intval($storeId));
        }

        if (!empty($childProductIds)){
            array_unshift($childProductIds, $productId);
            $select->where("product_id IN (" . implode(',', $childProductIds) . ")");
        } else {
            $select->where('product_id = ?', intval($productId));
        }

        return $connection->fetchAll($select);
    }

    /**
     * Get printformer-products prepared for frontend methods
     *
     * @param $productId
     * @param int $storeId
     * @param bool $subordinateSimpleProducts
     * @return array
     */
    public function getPrintformerProductsForFrontendConfigurationLogic($productId, int $storeId = 0, $childProductIds = []): array
    {
        $printformerProducts = [];

        foreach($this->getCatalogProductPrintformerProductsData($productId, $storeId, true, $childProductIds) as $row) {
            $printformerProduct = $this->productFactory->create();
            $this->resource->load($printformerProduct, $row['printformer_product_id']);

            if($printformerProduct->getId()) {
                $printformerProduct->setData('template_id', $row['printformer_product_id']);
                $printformerProduct->setData('product_id', $row['product_id']);
                $printformerProducts[] = $printformerProduct;
            }
        }

        return $printformerProducts;
    }

    /**
     * @param string $masterId
     * @return array
     */
    public function getCatalogProductPrintformerProductsByMasterId($masterId)
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from('catalog_product_printformer_product')
            ->where('master_id = ?', $masterId);
        return $connection->fetchAll($select);
    }

    /**
     * @param     $productId
     * @param int $storeId
     *
     * @return array
     */
    public function getCatalogProductPrintformerProductsArray($productId, $storeId = 0)
    {
        $result = $this->getCatalogProductPrintformerProductsData($productId, $storeId);

        foreach($result as &$row) {
            $printformerProduct = $this->productFactory->create();
            $this->resource->load($printformerProduct, $row['printformer_product_id']);

            if($printformerProduct->getId()) {
                $printformerProduct->setData('template_id', $row['printformer_product_id']);
                $printformerProduct->setData('product_id', $row['product_id']);
                $id = $row['id'];
                //todo: check better version to fix array merge performance issue
                $row = array_merge($row, $printformerProduct->getData());
                $row['id'] = $id;
            }
        }

        return $result;
    }

    /**
     * Prepare results from getCatalogProductPrintformerProductsData
     *
     * @param $productId
     * @param int $storeId
     * @return array
     */
    public function prepareCatalogProductPrintformerProductsData($productId, int $storeId = 0): array
    {
        //Todo: function can maybe merged with getCatalogProductPrintformerProductsData for better performance
        $catalogProductPrintformerProducts = [];

        $childProductIds = [];
        foreach($this->getCatalogProductPrintformerProductsData($productId, $storeId, true, $childProductIds) as $i => $row) {
            $catalogProductPrintformerProducts[$i] = new DataObject();
            $catalogProductPrintformerProducts[$i]->setCatalogProductPrintformerProduct(new DataObject($row));

            $printformerProduct = $this->productFactory->create();
            $this->resource->load($printformerProduct, $row['printformer_product_id']);
            if($printformerProduct->getId()) {
                $printformerProduct->setData('template_id', $row['printformer_product_id']);
                $printformerProduct->setData('product_id', $row['product_id']);
                $catalogProductPrintformerProducts[$i]->setPrintformerProduct($printformerProduct);
            }
        }

        return $catalogProductPrintformerProducts;
    }

    /**
     * Get draft-id from unique id with required pf-product-id and the main-page-product-id
     *
     * @param $printformerProductId
     * @param $productId
     * @return string
     */
    public function getDraftId($printformerProductId, $productId)
    {
        $draftId = '';
        $sessionUniqueId = $this->sessionHelper->getSessionUniqueIdByProductId($productId, $printformerProductId);

        if (isset($sessionUniqueId)) {
            /** @var Draft $draft */
            $draft = $this->draftFactory->create();
            $draftCollection = $draft->getCollection()
                ->addFieldToFilter('printformer_product_id', $printformerProductId)
                ->addFieldToFilter('product_id', $productId)
                ->addFieldToFilter('session_unique_id', ['eq' => $sessionUniqueId])
                ->setOrder('created_at', AbstractDb::SORT_ORDER_ASC);

            if ($draftCollection->count() > 0) {
                $draft = $draftCollection->getLastItem();
                if ($draft->getId() && $draft->getDraftId()) {
                    $draftId = $draft->getDraftId();
                }
            }
        }

        return $draftId;
    }

    /**
     * Get Draft id depends by your request / position from where request is sent.
     *
     * @return string
     */
    public function searchAndLoadDraftId(PrintformerProduct $printformerProduct)
    {
        $draftId = null;
        $productId = $this->_request->getParam('product_id');
        // Get draft ID on cart product edit page
        if ($this->_request->getActionName() == 'configure' && $this->_request->getParam('id') && $this->_request->getParam('product_id')) {
            $quoteItem = null;
            $wishlistItem = null;
            $id = (int)$this->_request->getParam('id');
            $productId = (int)$this->_request->getParam('product_id');
            if ($id) {
                switch ($this->_request->getModuleName()) {
                    case 'checkout':
                        $quoteItem = $this->cart->getQuote()->getItemById($id);
                        if ($quoteItem && $productId == $quoteItem->getProduct()->getId()) {
                            if ($quoteItem->getProductType() === $this->configHelper::CONFIGURABLE_TYPE_CODE) {
                                $children = $quoteItem->getChildren();
                                if (!empty($children)) {
                                    $firstChild = $children[0];
                                    if (!empty($firstChild)) {
                                        $draftId = $this->configHelper->loadDraftFromQuoteItem($firstChild, $printformerProduct->getProductId(), $printformerProduct->getId());
                                    }
                                }
                            } else {
                                $draftId = $this->configHelper->loadDraftFromQuoteItem($quoteItem, $printformerProduct->getProductId(), $printformerProduct->getId());
                            }
                        }
                        break;
                    case 'wishlist':
                        $wishlistItem = $this->wishlistItem->loadWithOptions($id);
                        if ($wishlistItem && $productId == $wishlistItem->getProductId()) {
                            //todo?: change logic to create/get/load correct draft for correct draft-id * maybe complete logic in helper
                            $draftId = $wishlistItem->getOptionByCode(InstallSchema::COLUMN_NAME_DRAFTID)->getValue();
                        }
                        //todo?: adjust to get draft id for child-products like on function loadDraftFromQuoteItem
                        break;
                    default:
                        break;
                }
            }
        } else {
            $productId = $printformerProduct->getProductId();
            $pfProductId = $printformerProduct->getId();
            $draftId = $this->getDraftId($pfProductId, $productId);
            if (empty($draftId) || $this->draftIsAlreadyUsedInCart($draftId)) {
                $draftId = null;
            }
        }

        return $draftId;
    }


    /**
     * @param integer $draftId
     *
     * @return array
     */
    public function getProductVariations($draftId = null)
    {
        $variations = [];
        if ($draftId) {
            $draft = $this->draftFactory->create()->load($draftId, 'draft_id');
            if ($draft->getFormatVariation()) {
                $variations[$this->configHelper->getFormatQueryParameter()] = $draft->getFormatVariation();
            }
            if ($draft->getColorVariation()) {
                $variations[$this->configHelper->getColorQueryParameter()] = $draft->getColorVariation();
            }
        }

        return $variations;
    }

    /**
     * @param integer $draftId
     *
     * @return array
     */
    public function getProductQty($draftId = null)
    {
        $qty = 1; //@todo min qty from sysconf?
        if ($draftId) {
            $draft = $this->draftFactory->create()//@todo add getDraft()
            ->load($draftId, 'draft_id');
            if ($draft->getQty()) {
                $qty = $draft->getQty();
            }
        }

        return $qty;
    }

    /**
     * To verify if current draft is already in any quote item handled
     *
     * @param $draftId
     * @return bool
     */
    public function draftIsAlreadyUsedInCart(
        $draftId
    )
    {
        $quoteItems = $this->cart->getQuote()->getItems();
        $result = false;

        if (is_array($quoteItems)) {
            foreach ($quoteItems as $quoteItem) {
                if ($quoteItem->getProductType() == ConfigurableType::TYPE_CODE) {
                    if ($quoteItem) {
                        if ($quoteItem->getDraftId() == $draftId) {
                            $result = true;
                        }

                        if (!empty($children = $quoteItem->getChildren())) {
                            foreach ($children as $child) {
                                if ($child->getData(InstallSchema::COLUMN_NAME_DRAFTID) == $draftId) {
                                    $result = true;
                                }
                            }
                        }
                    }
                } else {
                    if ($quoteItem->getDraftId() == $draftId) {
                        $result = true;
                    }
                }
            }
        }

        return $result;
    }
}