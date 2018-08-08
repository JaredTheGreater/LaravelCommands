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
    protected $signature = 'shopify:orders-update {store?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Shopify update orders job. One argument required: hex or acembly.';
	
	/**
     * The Shopify store to work with.
     *
     * @var string
     */
    protected $store;
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
		Log::info("Order update job for $store initiated.");
		$this->navOrdersUpdate($store);
		Log::info("Order update job for $store completed.");
    }
	
	public function navOrdersUpdate($store){
		
		$timeStampOB = new \DateTime('10 min ago', new \DateTimeZone('America/Denver'));
		$timeStamp = $timeStampOB->format(DateTime::ATOM);
		
		// set up basic FTP connection
		$conn_id = ftp_connect(SPS_FTP_SERVER);
		$login_result = ftp_login($conn_id, SPS_FTP_USERNAME, SPS_FTP_USERPASS);
		if ((!$conn_id) || (!$login_result)) {  
			Log::error("FTP connection has failed! Attempted to connect to " . SPS_FTP_SERVER . " for user " . SPS_FTP_USERNAME);
			exit;
		} else {
			Log::info("Connected to " . SPS_FTP_SERVER . " for user " . SPS_FTP_USERNAME);
		}
		ftp_pasv($conn_id, true);
		
		// Get all Order update files from FTP server
		$files[] = ftp_nlist($conn_id, "/sftp-arp-root/HEX/out/orders/");
		
		// Get contents of NAV Order feed and process individually with AMZ MWS 
        foreach($files as $file) {
			// set url and provide authentication values
			if($file){
				switch($store) {
					case "hex":
						$feed = "/sftp-arp-root/HEX/out/orders/". $file[0];		
						$feedHandle = fopen(resource_path('inc/xml/test/' . $this->store . 'order_' . $file[0] . "_" . $timeStamp . '.xml'), 'w+');
						break;
					case "acembly":
						$feed = "/sftp-arp-root/Acembly/out/orders/". $file[0];		
						$feedHandle = fopen(resource_path('inc/xml/test/' . $this->store . 'order_' . $file[0] . "_" . $timeStamp . '.xml'), 'w+');
						break;
					default:
						Log::error("No store name provided. Exiting job.");
						echo "No store name provided. Exiting job.";
						exit;
						break;
				}
			} else {
				Log::info("No files found");
				Log::info("Exiting Shopify order update job.");
				exit;
			}
						
			if(ftp_fget($conn_id, $feedHandle, $feed, FTP_ASCII, 0)) {
				Log::info("successfully written to $feedHandle");
			} else {
				Log::error("There was a problem while downloading $feed to $feedHandle");
			}
			rewind($feedHandle);
			
			$feedContents = stream_get_contents($feedHandle);
			rewind($feedHandle);
			
			// Parse XML string into JSON
			$xml = simplexml_load_string($feedContents, "SimpleXMLElement", LIBXML_NOCDATA);
			$feedContentsJSON = json_encode($xml);

			/**
			 * cURL set up
			 */
			// create curl resource 
			$curl = curl_init(); 
			// set url and provide authentication values
			switch($this->store) {
				case "hex":
					curl_setopt($curl, CURLOPT_URL, "https://hex-brand.myshopify.com/admin/" . $feedContentsJSON["order"]["id"] . ".json");
					curl_setopt($curl, CURLOPT_USERPWD, HEX_API_KEY . ":" . HEX_PASS);
					break;
				case "acembly":
					curl_setopt($curl, CURLOPT_URL, "https://acembly.myshopify.com/admin/" . $feedContentsJSON["order"]["id"] . ".json");
					break;
				default:
					Log::error("No store name provided. Exiting job.");
					echo "No store name provided. Exiting job.";
					exit;
					break;
			}
			//Set header values, set request type as "PUT", identify fields to be sent, and return response as a string.
			curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json",'Content-Length: ' . strlen($feedContentsJSON)));
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
			curl_setopt($ch, CURLOPT_POSTFIELDS,$feedContentsJSON);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			// Assign the response to $output 
			$output = curl_exec($curl);
			// close curl resource to free up system resources 
			curl_close($curl);
			
			// Delete the file from the FTP server
			if (ftp_delete($conn_id, $feed)) {
				Log::info("$feed deleted successful");
			} else {
				Log::error("could not delete $feed");
			}
			
			@fclose($feedHandle); 
			
		}
		// Close FTP connection
		ftp_close($conn_id);
		
	}
	
}
?>