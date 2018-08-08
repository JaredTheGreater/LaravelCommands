<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Illuminate\Contracts\Bus\Dispatcurler;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;
use Storage;
use App;
use SimpleXmlElement;
use DateTime;
use Illuminate\Support\Facades\Log;

// Config file required for Shopify API Authentacation:
require_once(config_path('.shopify.config.php'));
require_once(config_path('.sps.commerce.php'));

class ShopifyOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:orders {store?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Shopify fetch orders job. Argument (store) options: hex, acembly.';
	
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
    public function handle()
    {
        $store = $this->argument('store');
		Log::info("Get orders job for $store initiated.");
		$this->fetchOrders($store);
		Log::info("Get orders job for $store completed.");
    }
	
	public function fetchOrders($store){
		
		$timeStampOB = new \DateTime('10 min ago', new \DateTimeZone('America/Denver'));
		$timeStamp = $timeStampOB->format(DateTime::ATOM);
		/**
		 * cURL set up
		 */
		// create curl resource 
        $curl = curl_init(); 
		//Set header values
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
		// set url and provide authentication values
		switch($store) {
			case "hex":
				curl_setopt($curl, CURLOPT_URL, "https://hex-brand.myshopify.com/admin/orders.json?created_at_min=" . $timeStamp);
				curl_setopt($curl, CURLOPT_USERPWD, HEX_API_KEY . ":" . HEX_PASS);
				break;
			case "acembly":
				curl_setopt($curl, CURLOPT_URL, "https://acembly.myshopify.com/admin/orders.json?created_at_min=" . $timeStamp);
				break;
			default:
				Log::error("No store name provided. Exiting job.");
				echo "No store name provided. Exiting job.";
				exit;
				break;
		}
		//return the transfer as a string		
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); 
        // $output contains the output string 
        $output = curl_exec($curl);
        // close curl resource to free up system resources 
        curl_close($curl);
		
		/**
		 * Process each $output order into an array of orders
		 */
		$outputArray = json_decode($output, TRUE);
		$ordersArray = array();
		foreach($outputArray["orders"] as $order){
			$ordersArray[] = $this->array2xml($order, false);
		}
		if(count($ordersArray) <= 0){
			Log::info("No new orders at this time.");
			exit;
		}
		/**
		 * Process each $ordersArray into xml
		 */
		$this->splitOrders($ordersArray, $store);
	}
	
	public function array2xml($array, $xml = false){
		if($xml === false){
			$xml = new SimpleXMLElement('<Order/>');
		}
		foreach($array as $key => $value){
			$key = ($key == "0" ? $key = "zero" : $key);
			if(is_array($value)){
				$this->array2xml($value, $xml->addChild(htmlspecialchars($key), htmlspecialchars($value)));
			} else {
				$xml->addChild(htmlspecialchars($key), htmlspecialchars($value));
			}
		}
		return $xml->asXML();
	}
	
	/**** Break out Orders into individual files and upload via FTP ****/
	public function splitOrders($ordersArray, $store){
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
				
		// Create new XML file for each	order	
		foreach($ordersArray as $order){
			// Create DOMDocument Object to hold XML
			$orderFile = new \DOMDocument('1.0');
			$orderFile->preserveWhiteSpace = true;
			$orderFile->formatOutput = true;
			// Add Order to document
			$orderFile->loadXML($order);
			// Save file with Order ID in file name
			$orderIdNode = $orderFile->getElementsByTagName("id");
			$orderId = $orderIdNode[0]->nodeValue;
			$orderFile->save(resource_path('inc/xml/test/' . $store . "Order_" . $orderId . '.xml'));
			// Upload file to SPSCommerce via FTP
			$file = resource_path('inc/xml/test/' . $store . "Order_" . $orderId . ".xml");
			$destination = "/sftp-arp-root/HEX/in/" . $store . "Order_" . $orderId . ".xml";
			$upload = ftp_put($conn_id, $destination, $file, FTP_ASCII);
			if (!$upload) {
				Log::error("FTP upload of $file has failed!");
			} 
		}
		ftp_close($conn_id);
	}
}
?>