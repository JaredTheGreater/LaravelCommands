<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;
use Storage;
use App;
use SimpleXmlElement;
use DateTime;
use Illuminate\Support\Facades\Log;

/********************************************************/
// AMZ MWS libraries are build for DI. Must use Includes:
/********************************************************/

// Config file required for AMZ MWS Authentacation:
require_once(config_path('.campclub.amws.php'));
require_once(config_path('.sps.commerce.php'));

// Required class files from AMZ MWS Orders Library:
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceOrders/MarketplaceWebServiceOrders_Mock.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceOrders/MarketplaceWebServiceOrders_Interface.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceOrders/MarketplaceWebServiceOrders_Client.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceOrders/MarketplaceWebServiceOrders_Exception.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceOrders/Model/MarketplaceWebServiceOrders_Model_ListOrdersRequest.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceOrders/Model/MarketplaceWebServiceOrders_Model_ListOrderItemsRequest.php'));

class CampClubAmazonOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campclub:orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Amazon MWS orders job for Camp Club, LLC';
	
	/**
	 * The service URL for AMZ Orders endpoint
	 * @var string
	 */
	protected $serviceUrl = "https://mws.amazonservices.com/Orders/2013-09-01";
	
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle() {
        try {
			Log::info("Camp Club orders job initiated.");
			$orders = $this->getOrders();
			$ordersDoc = new \DOMDocument();
			$ordersDoc->preserveWhiteSpace = false;
			$ordersDoc->formatOutput = true;
			$ordersDoc->loadXML($orders);
			$this->splitOrders($ordersDoc);
			Log::info("Camp Club orders job completed.");
		} catch (\MarketplaceWebService_Exception $ex) {
			$errorMessage = 
				"Error exception: " . $ex->getMessage() . 
				" Status code: " . $ex->getStatusCode() . 
				" Error code: " . $ex->getErrorCode() . 
				" Error type: " . $ex->getErrorType() . 
				" Request ID: " . $ex->getRequestId();
			Log::error($errorMessage);
		}
    }
	
	/**** Fetch and Return list of Orders ****/
    public function getOrders() {

		/** Server Configs **/
        $config = array (
			'ServiceURL' => $this->serviceUrl,
			'ProxyHost' => null,
			'ProxyPort' => -1,
			'ProxyUsername' => null,
			'ProxyPassword' => null,
			'MaxErrorRetry' => 3
        );
		
		/** AMZ MWS details **/
		$service = new \MarketplaceWebServiceOrders_Client(
			CC_AWS_ACCESS_KEY_ID, 
			CC_AWS_SECRET_ACCESS_KEY, 
			CC_APPLICATION_NAME,
			CC_APPLICATION_VERSION,
			$config
		);

        // Amazon MWS API is picky about timestamps
        $createdAfter = new \DateTime('10 min ago', new \DateTimeZone('UTC'));
        $createdAfterFormatted = $createdAfter->format(DATE_FORMAT);

        // @TODO: set request. Action can be passed as MarketplaceWebServiceOrders_Model_ListOrders
        $ordersRequest = new \MarketplaceWebServiceOrders_Model_ListOrdersRequest();
        $ordersRequest->setSellerId(CC_MERCHANT_ID);
        $ordersRequest->setLastUpdatedAfter($createdAfterFormatted);
		$orderStatus = ['Unshipped','PartiallyShipped'];
		$ordersRequest->setOrderStatus($orderStatus);
        $ordersRequest->setMarketplaceId(CC_MARKETPLACE_ID);
        
        $ordersRequestResponse = $service->ListOrders($ordersRequest);

        $dom = new \DOMDocument();
        $dom->loadXML($ordersRequestResponse->toXML());
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = true;
		$orderNodes = $dom->getElementsByTagName("Order");
		$orders = new \DOMDocument("1.0");
		$root = $orders->createElement("AllOrders");
		$orders->appendChild($root);
		$orders->preserveWhiteSpace = true;
		$orders->formatOutput = true;
		foreach($orderNodes as $node){
			$orderTag = $orders->importNode($node, true);
			$orders->documentElement->appendChild($orderTag);
		}
		$ordersXMLstring = $orders->saveXML();
		$ordersIterator = simplexml_load_string($ordersXMLstring);
		foreach($ordersIterator->Order as $order){
			// Get AMZ order ID
			$amazonOrderId = $order->AmazonOrderId;
			// Get order items from AMZ
			$orderItemsRequest = new \MarketplaceWebServiceOrders_Model_ListOrderItemsRequest();
			$orderItemsRequest->setSellerId(CC_MERCHANT_ID);
			$orderItemsRequest->setAmazonOrderId($amazonOrderId);
			$orderItemsResponse = $service->ListOrderItems($orderItemsRequest);
			// Put orders in document
			$orderItemsResult = new \DOMDocument();
			$orderItemsResult->loadXML($orderItemsResponse->toXML());
			$orderItemsResult->preserveWhiteSpace = true;
			$orderItemsResult->formatOutput = true;
			$orderItemsResultXML = $orderItemsResult->saveXML();
			$orderItemsResultSXE = simplexml_load_string($orderItemsResultXML);
			// Create "LineItems" parent
			$order->addChild("LineItems");
			// Add order items as "LineItem"
			foreach($orderItemsResultSXE->ListOrderItemsResult->OrderItems as $LineItem) {
				$order->LineItems->addChild("LineItem");
				foreach($LineItem as $detail){
					$order->LineItems->LineItem->addChild("ASIN", $detail->ASIN);
					$order->LineItems->LineItem->addChild("SellerSKU", $detail->SellerSKU);
					$order->LineItems->LineItem->addChild("OrderItemId", $detail->OrderItemId);
					$order->LineItems->LineItem->addChild("Title", $detail->Title);
					$order->LineItems->LineItem->addChild("QuantityOrdered", $detail->QuantityOrdered);
					$order->LineItems->LineItem->addChild("QuantityShipped", $detail->QuantityShipped);
					$order->LineItems->LineItem->addChild("NumberOfItems", $detail->ProductInfo->NumberOfItems);
					$order->LineItems->LineItem->addChild("ItemPriceCurrencyCode", $detail->ItemPrice->CurrencyCode);
					$order->LineItems->LineItem->addChild("ItemPriceAmount", $detail->ItemPrice->Amount);
					$order->LineItems->LineItem->addChild("ItemTaxCurrencyCode", $detail->ItemTax->CurrencyCode);
					$order->LineItems->LineItem->addChild("ItemTaxAmount", $detail->ItemTax->Amount);
					$order->LineItems->LineItem->addChild("PromotionDiscountCurrencyCode", $detail->PromotionDiscount->CurrencyCode);
					$order->LineItems->LineItem->addChild("PromotionDiscountAmount", $detail->PromotionDiscount->Amount);
					$order->LineItems->LineItem->addChild("PromotionId", $detail->PromotionIds[0]);
					$order->LineItems->LineItem->addChild("IsGift", $detail->IsGift);
				}
			}
		}
		$finalDoc = $ordersIterator->asXML();
		return $finalDoc;
    }
	
	/**** Break out Orders into individual files and upload via FTP ****/
	public function splitOrders($xml){
		// set up basic connection
		$conn_id = ftp_connect(SPS_FTP_SERVER);
		$login_result = ftp_login($conn_id, SPS_FTP_USERNAME, SPS_FTP_USERPASS);
		if ((!$conn_id) || (!$login_result)) {  
			Log::error("FTP connection has failed! Attempted to connect to " . SPS_FTP_SERVER . " for user " . SPS_FTP_USERNAME);
			exit;
		} else {
			Log::info("Connected to " . SPS_FTP_SERVER . " for user " . SPS_FTP_USERNAME);
		}
		ftp_pasv($conn_id, true);
		
		// Get all Order tags
		$orderNodes = $xml->getElementsByTagName("Order");
		if(count($orderNodes) < 1){
			Log::info("No orders were returned. Exiting Camp Club orders job.");
			exit;
		}
		// Create new file for each			
		foreach($orderNodes as $node){
			// Create DOMDocument Object to hold XML
			$orderFile = new \DOMDocument("1.0");
			// Build Document
			$root = $orderFile->createElement("CampClubAMZOrderEnvelope");
			$orderFile->appendChild($root);
			$orderFile->preserveWhiteSpace = true;
			$orderFile->formatOutput = true;
			// Add Order to document
			$orderTag = $orderFile->importNode($node, true);
			$orderFile->documentElement->appendChild($orderTag);
			// Save file with Order ID in file name
			$amazonOrderIdNode = $orderFile->getElementsByTagName("AmazonOrderId");
			$amazonOrderId = $amazonOrderIdNode[0]->nodeValue;
			$orderFile->save(resource_path('inc/xml/out/CampClubAMZorder_'. $amazonOrderId . '.xml'));
			// Upload file to SPSCommerce via FTP
			$file = resource_path('inc/xml/out/CampClubAMZorder_'. $amazonOrderId . '.xml');
			$destination = "/sftp-arp-root/CampClub/Orders/in/CampClubAMZorder_". $amazonOrderId . ".xml";
			$upload = ftp_put($conn_id, $destination, $file, FTP_ASCII);
			if (!$upload) {
				Log::error("FTP upload of Camp Club AMZ order $amazonOrderId has failed!");
			} else {
				Log::info("FTP upload of Camp Club AMZ order $amazonOrderId was successful.");
			}
		}
		ftp_close($conn_id);
	}
	
	// Removes the namespace $ns from all elements in the DOMDocument $doc
	public function remove_dom_namespace($doc, $ns) {
		$finder = new \DOMXPath($doc);
		$nodes = $finder->query("//*[namespace::{$ns} and not(../namespace::{$ns})]");
		foreach ($nodes as $n) {
			$ns_uri = $n->lookupNamespaceURI($ns);
			$n->removeAttributeNS($ns_uri, $ns);
		}
	}
	
	/**** Send an acknowledgement of the order back to AMZ ****/
	public function sendAcknowledgement($orderFile){
		
	}
}
?>