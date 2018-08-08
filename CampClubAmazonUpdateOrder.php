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
// AMZ MWS libraries are not build for DI. Must use Includes:
/********************************************************/

// Config file required for AMZ MWS Authentacation:
require_once(config_path('.campclub.amws.php'));
require_once(config_path('.sps.commerce.php'));

// Required class files from AMS MWS Feeds Library:
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/MarketplaceWebService_Mock.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/MarketplaceWebService_Model.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/MarketplaceWebService_Interface.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/MarketplaceWebService_Client.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/MarketplaceWebService_Exception.php'));
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/Model/MarketplaceWebService_Model_SubmitFeedRequest.php'));

class CampClubAmazonUpdateOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campclub:update-order {updateType?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Amazon MWS update order job for Camp Club, LLC. One argument is required: acknowledgement, adjustment, or fulfillment.';
	
	/**
	 * The service URL for AMZ Orders endpoint
	 * @var string
	 */
	protected $serviceUrl = "https://mws.amazonservices.com";
		
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
        $updateType = $this->argument('updateType');
		Log::info("Camp Club order $updateType job initiated.");
		switch($updateType) {
			case "acknowledgement":
				$this->updateOrdersAcknowledgement();
				break;
			case "adjustment":
				$this->updateOrdersAdjustment();
				break;
			case "fulfillment":
				$this->updateOrdersFulfillment();
				break;
			default:
				$this->updateOrdersAcknowledgement();
				break;
		}
		Log::info("Camp Club order $updateType job completed.");
    }
	
	/**** Send Order Acknowledgement Feed ****/
	public function updateOrdersAcknowledgement(){
		
		$timeStampOB = new \DateTime('now', new \DateTimeZone('America/Denver'));
		$timeStamp = $timeStampOB->format('Y-m-d_H:i:s');
		$files = array();
		
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
		
		// Server Configs
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
		
		// Get all Order update files from FTP server
		$files[] = ftp_nlist($conn_id, "/sftp-arp-root/CampClub/Orders/out/Acknowledgement/");
		
		// Get contents of NAV Order feed and process individually with AMZ MWS 
        foreach($files as $file) {
			if($file){
				$feed = "/sftp-arp-root/CampClub/Orders/out/Acknowledgement/". $file[0];		
				$feedHandle = fopen(resource_path('inc/xml/test/ccOrderAcknowledgement_' . $timeStamp . '.xml'), 'w+');
			} else {
				Log::info("No files found in /sftp-arp-root/CampClub/Orders/out/Acknowledgement/");
				Log::info("Exiting Camp Club order acknowledgement job.");
				exit;
			}
			
			if(ftp_fget($conn_id, $feedHandle, $feed, FTP_ASCII, 0)) {
				Log::info("successfully written to $feedHandle");
			} else {
				Log::error("There was a problem while downloading $feed to $feedHandle");
			}
			rewind($feedHandle);
			
			$parameters = array (
				'Merchant' => CC_MERCHANT_ID,
				'MarketplaceId' => CC_MARKETPLACE_ID,
				'FeedType' => '_POST_ORDER_ACKNOWLEDGEMENT_DATA_',
				'FeedContent' => $feedHandle,
				'PurgeAndReplace' => false,
				'ContentMd5' => base64_encode(md5(stream_get_contents($feedHandle), true))
			);
			$feedContents = stream_get_contents($feedHandle);
			rewind($feedHandle);
			
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
			if (ftp_delete($conn_id, $feed)) {
				Log::info("$feed deleted successful");
			} else {
				Log::error("could not delete $feed");
			}
			
			@fclose($feedHandle); 
		}
		ftp_close($conn_id);		
	}
	
	/**** Send Order Adjustment Feed ****/
	public function updateOrdersAdjustment(){
		$timeStampOB = new \DateTime('now', new \DateTimeZone('America/Denver'));
		$timeStamp = $timeStampOB->format('Y-m-d_H:i:s');
		$files = array();
		
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
		
		// Server Configs
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
		
		// Get all Order update files from FTP server
		$files[] = ftp_nlist($conn_id, "/sftp-arp-root/CampClub/Orders/out/Adjustment/");
		
		// Get contents of NAV Order feed and process individually with AMZ MWS 
        foreach($files as $file) {
			if($file){
				$feed = "/sftp-arp-root/CampClub/Orders/out/Adjustment/". $file[0];		
				$feedHandle = fopen(resource_path('inc/xml/test/ccOrderAdjustment_' . $timeStamp . '.xml'), 'w+');
			} else {
				Log::info("No files found in /sftp-arp-root/CampClub/Orders/out/Adjustment/");
				Log::info("Exiting Camp Club order adjustment job.");
				exit;
			}
						
			if(ftp_fget($conn_id, $feedHandle, $feed, FTP_ASCII, 0)) {
				Log::info("successfully written to $feedHandle");
			} else {
				Log::error("There was a problem while downloading $feed to $feedHandle");
			}
			rewind($feedHandle);
			
			$parameters = array (
				'Merchant' => CC_MERCHANT_ID,
				'MarketplaceId' => CC_MARKETPLACE_ID,
				'FeedType' => '_POST_PAYMENT_ADJUSTMENT_DATA_',
				'FeedContent' => $feedHandle,
				'PurgeAndReplace' => false,
				'ContentMd5' => base64_encode(md5(stream_get_contents($feedHandle), true))
			);
			$feedContents = stream_get_contents($feedHandle);
			rewind($feedHandle);
			
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
			if (ftp_delete($conn_id, $feed)) {
				Log::info("$feed deleted successful");
			} else {
				Log::error("could not delete $feed");
			}
			
			@fclose($feedHandle); 
		}
		ftp_close($conn_id);		
	}
	
	/**** Send Order Fulfillment Feed ****/
	public function updateOrdersFulfillment(){
		$timeStampOB = new \DateTime('now', new \DateTimeZone('America/Denver'));
		$timeStamp = $timeStampOB->format('Y-m-d_H:i:s');
		$files = array();
		
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
		
		// Server Configs
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
		
		// Get all Order update files from FTP server
		$files[] = ftp_nlist($conn_id, "/sftp-arp-root/CampClub/Orders/out/Fulfillment/");
		
		// Get contents of NAV Order feed and process individually with AMZ MWS 
        foreach($files as $file) {
			if($file){
				$feed = "/sftp-arp-root/CampClub/Orders/out/Fulfillment/". $file[0];		
				$feedHandle = fopen(resource_path('inc/xml/test/ccOrderFulfillment_' . $timeStamp . '.xml'), 'w+');
			} else {
				Log::info("No files found in /sftp-arp-root/CampClub/Orders/out/Fulfillment/");
				Log::info("Exiting Camp Club order fulfillment job.");
				exit;
			}
						
			if(ftp_fget($conn_id, $feedHandle, $feed, FTP_ASCII, 0)) {
				Log::info("successfully written to $feedHandle");
			} else {
				Log::error("There was a problem while downloading $feed to $feedHandle");
			}
			rewind($feedHandle);
			
			$parameters = array (
				'Merchant' => CC_MERCHANT_ID,
				'MarketplaceId' => CC_MARKETPLACE_ID,
				'FeedType' => '_POST_ORDER_FULFILLMENT_DATA_',
				'FeedContent' => $feedHandle,
				'PurgeAndReplace' => false,
				'ContentMd5' => base64_encode(md5(stream_get_contents($feedHandle), true))
			);
			$feedContents = stream_get_contents($feedHandle);
			rewind($feedHandle);
			
			$request = new \MarketplaceWebService_Model_SubmitFeedRequest($parameters);
													
			try {
				$response = $service->submitFeed($request);
				if ($response->isSetSubmitFeedResult()) { 
					$submitFeedResult = $response->getSubmitFeedResult();
					if ($submitFeedResult->isSetFeedSubmissionInfo()) { 
						$feedSubmissionInfo = $submitFeedResult->getFeedSubmissionInfo();
						$feedSubmissionID = $feedSubmissionInfo->getFeedSubmissionId();
						$submissionInfo = "Feed details: type " . $feedSubmissionInfo->getFeedType() . " successfully submitted on " . $feedSubmissionInfo->getSubmittedDate()->format(DATE_FORMAT);
						Log::info($submissionInfo);
						Log::info("Camp Club check feed submission job for ID $feedSubmissionID initiated.");
						$successfulUpdate = $this->call('campclub:feed-result', ['feedSubmissionId' => $feedSubmissionID]);
						for($i=3;$i>0;$i--){
							if($successfulUpdate["pass"] == 0){
								sleep(60);
								$successfulUpdate = $this->call('campclub:feed-result', ['feedSubmissionId' => $feedSubmissionID]);
							}
						}
						if($successfulUpdate["pass"] == 0){
							Log::error($successfulUpdate[1]);
						} else {
							Log::info($successfulUpdate[1]);
						}
						Log::info("Camp Club check feed submission job for ID $feedSubmissionID completed.");
						// Delete the file from the FTP server
						if (ftp_delete($conn_id, $feed)) {
							Log::info("$feed deleted successful");
						} else {
							Log::error("could not delete $feed");
						}
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
			
			@fclose($feedHandle); 
		}
		ftp_close($conn_id);		
	}
}
?>