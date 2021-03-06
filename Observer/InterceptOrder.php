<?php
namespace Feedaty\Badge\Observer;

use Feedaty\Badge\Model\Config\Source\WebService;
use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\UrlInterface;
use \Magento\Catalog\Helper\Image;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use Feedaty\Badge\Helper\Data as DataHelp;
use \Magento\Framework\App\Request\Http;
use \Magento\Framework\ObjectManagerInterface;

class InterceptOrder implements ObserverInterface
{

    /**
    * @var \Magento\Framework\App\Config\ScopeConfigInterface
    */
    protected $scopeConfig;

    /**
    * @var \Magento\Store\Model\StoreManagerInterface
    */
    protected $storeManager;

    /**
    * @var \Magento\Catalog\Helper\Image
    */
    protected $imageHelper;

    /**
    * @var \Magento\Framework\ObjectManagerInterface
    */
    protected $objectManager;

	  /**
	  * @var \Magento\Sales\Api\OrderRepositoryInterface
	  */
	  protected $orderRepository;

	  /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
    * Constructor
    *
    */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        Image $imageHelper,
        DataHelp $dataHelpler,
        WebService $fdservice,
        Http $request, //remove http dependecy
        ObjectManagerInterface $objectmanager,
    		\Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
    		\Psr\Log\LoggerInterface $logger
        )
    {
        $this->_scopeConfig = $scopeConfig;
        $this->_storeManager = $storeManager;
        $this->imageHelper = $imageHelper;
        $this->_dataHelpler = $dataHelpler;
        $this->_fdservice = $fdservice;
        $this->_request = $request;
        $this->_objectManager = $objectmanager;
    		$this->orderRepository = $orderRepository;
    		$this->logger = $logger;
    }

    /**
    * Function execute
    *
    * @param $observer
    */
    public function execute(\Magento\Framework\Event\Observer $observer){

        $order = $observer->getEvent()->getOrder();
        $store = $this->_storeManager->getStore($order->getStore_id());
        $order_id = $order->getIncrementId();
        $billingAddress = $order->getBillingAddress()->getCountryId();
        $verify = 0;

        $merchant = $store->getConfig('feedaty_global/feedaty_preferences/feedaty_code');
        $secret = $store->getConfig('feedaty_global/feedaty_preferences/feedaty_secret');
        $orderopt = $store->getConfig('feedaty_global/feedaty_sendorder/sendorder');

        foreach (($order->getAllStatusHistory()) as $orderComment)
        {
            if ($orderComment->getStatus() === $orderopt) $verify++;
        }

        if ($order->getStatus() == $orderopt && $verify <= 1)
        {

            $baseurl_store = $store->getBaseUrl(UrlInterface::URL_TYPE_LINK);

            $objproducts = $order->getAllItems();

            unset($fd_products);

            foreach ($objproducts as $itemId => $item)
            {
                unset($tmp);

                if (!$item->getParentItem())
                {
                    $fd_oProduct = $this->_objectManager->get('Magento\Catalog\Model\Product')->load((int) $item->getProductId());

                    $tmp['SKU'] = $item->getProductId();
                    $tmp['URL'] = $fd_oProduct->getUrlModel()->getUrl($fd_oProduct);

                        //get the image url
                    if ($fd_oProduct->getImage() != "no_selection")
                    {
                        //$store = $this->_objectManager->get('Magento\Store\Model\StoreManagerInterface')->getStore();
                        $tmp['ThumbnailURL'] = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/product' . $fd_oProduct->getImage();
                    }
                    else
                    {
                        $tmp['ThumbnailURL'] = "";
                    }

                    $tmp['Name'] = $item->getName();
                    $tmp['Brand'] = $item->getBrand();
                    if ($tmp['Brand'] === null) $tmp['Brand']  = "";

                    //$tmp['Price'] = $item->getPrice();
                    $fd_products[] = $tmp;
                }
            }

            $productMetadata = $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface');

			      $orderFromRepo = $this->orderRepository->get($order->getId());

            // Formatting the array to be sent
            $tmp_order['ID'] = $order->getId();
            $tmp_order['Date'] = date("Y-m-d H:i:s");
            $tmp_order['CustomerEmail'] = $orderFromRepo->getCustomerEmail();
            $tmp_order['CustomerID'] = $orderFromRepo->getCustomerEmail();
            $tmp_order['Platform'] = "Magento ".$productMetadata->getVersion();

            if ($billingAddress == 'IT' || $billingAddress == 'EN' || $billingAddress == 'ES' || $billingAddress == 'DE' || $billingAddress == 'FR')
            {
                $tmp_order['Culture'] = strtolower($billingAddress);
            }
            else $tmp_order['Culture'] = 'en';

            $tmp_order['Products'] = $fd_products;
            $fd_data[] = $tmp_order;
            //$this->logger->debug('OOOOOOOOOOOOOOOOOOOOOOOOOOOOOOO------ ' . print_r($fd_data, true));
            // send to feedaty
            $this->_fdservice->send_order($merchant,$secret,$fd_data);

        }
    }
}
