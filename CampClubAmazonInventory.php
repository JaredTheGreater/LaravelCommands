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

/**
 * AMZ MWS libraries are not build for DI. Must use Includes:
 */

// Config files
require_once(config_path('.campclub.amws.php'));
require_once(config_path('.sps.commerce.php'));

// Required class files from AMS MWS Feeds Library:
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/MarketplaceWebService_Mock.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/MarketplaceWebService_Model.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/MarketplaceWebService_Interface.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/MarketplaceWebService_Client.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/MarketplaceWebService_Exception.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/Model/MarketplaceWebService_Model_SubmitFeedRequest.php'));

class CampClubAmazonInventory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campclub:inventory';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Amazon MWS inventory update job for Camp Club, LLC';
	
	/**
     * The United States AMZ MWS end point.
     *
     * @var string
     */
	protected $serviceUrl = "https://mws.amazonservices.com";
		
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(){
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(){
		Log::info("Camp Club inventory job initiated.");
		$this->sendInventoryFeed();
		Log::info("Camp Club inventory job complete.");
    }
	
	/**** Send inventory update feed ****/
    protected function sendInventoryFeed() {
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
		
		/** AMZ MWS Server Configs **/
		$config = [
			'ServiceURL' => $this->serviceUrl,
			'ProxyHost' => null,
			'ProxyPort' => -1,
			'MaxErrorRetry' => 3
		];

		/** AMZ MWS details **/
		$service = new \MarketplaceWebService_Client(
			CC_AWS_ACCESS_KEY_ID, 
			CC_AWS_SECRET_ACCESS_KEY, 
			$config,
			CC_APPLICATION_NAME,
			CC_APPLICATION_VERSION
		);
		
		/** Get contents of NAV inventory feed **/
        $feed = "/sftp-arp-root/CampClub/Inventory/out/inventory.xml";
		$timeStampOB = new \DateTime('now', new \DateTimeZone('America/Denver'));
		$timeStamp = $timeStampOB->format('Y-m-d_H:i:s');
        $feedHandle = @fopen(resource_path('inc/xml/out/campclubInventoryUpdate_' . $timeStamp . '.xml'), 'w+');
        if(@ftp_fget($conn_id, $feedHandle, $feed, FTP_ASCII, 0)) {
			Log::info("successfully written to $feedHandle");
		} else {
			Log::error("There was a problem while downloading $feed to $feedHandle");
		}
		rewind($feedHandle);
		
		/** Set up & send AMWS call **/
        $parameters = array (
          'Merchant' => CC_MERCHANT_ID,
          'MarketplaceId' => CC_MARKETPLACE_ID,
          'FeedType' => '_POST_INVENTORY_AVAILABILITY_DATA_',
          'FeedContent' => $feedHandle,
          'PurgeAndReplace' => false,
          'ContentMd5' => base64_encode(md5(stream_get_contents($feedHandle), true))
        );

        $request = new \MarketplaceWebService_Model_SubmitFeedRequest($parameters);
                                                
        try {
            $response = $service->submitFeed($request);
            if ($response->isSetSubmitFeedResult()) { 
				$submitFeedResult = $response->getSubmitFeedResult();
				if ($submitFeedResult->isSetFeedSubmissionInfo()) { 
					$feedSubmissionInfo = $submitFeedResult->getFeedSubmissionInfo();
					$submissionInfo = "Feed details: type " . $feedSubmissionInfo->getFeedType() . " submitted on " . $feedSubmissionInfo->getSubmittedDate()->format(DATE_FORMAT);
					Log::info($submissionInfo);
				}
            } 
        } catch (\MarketplaceWebService_Exception $ex) {
				$errorMessage = 
					"Error exception: " . $ex->getMessage() . 
					" Status code: " . $ex->getStatusCode() . 
					" Error code: " . $ex->getErrorCode() . 
					" Error type: " . $ex->getErrorType() . 
					" Request ID: " . $ex->getRequestId();
				Log::error($errorMessage);
        }
		
		// Delete the file from the FTP server
		if (@ftp_delete($conn_id, $feed)) {
			Log::info("$feed deleted successful");
		} else {
			Log::error("could not delete $feed");
		}
		
		@fclose($feedHandle);
    }
}

?>