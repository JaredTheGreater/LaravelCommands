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
require_once(resource_path('inc/amzmwslibrary/MarketplaceWebServiceFeeds/Model/MarketplaceWebService_Model_GetFeedSubmissionResultRequest.php'));

class CampClubAmazonFeedResult extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campclub:feed-result {feedSubmissionId?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Amazon MWS update order job for Camp Club, LLC. One argument is required: FeedSumbissionID.';
	
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
		$feedSubmissionId = $this->argument('feedSubmissionId');
		$result = $this->checkFeedSubmissionResult($feedSubmissionId);
		return $result;
    }
	
	public function checkFeedSubmissionResult($feedSubmissionId){
		
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

		$request = new \MarketplaceWebService_Model_GetFeedSubmissionResultRequest();
		$request->setMerchant(CC_MERCHANT_ID);
		$request->setFeedSubmissionId($feedSubmissionId);
		$request->setFeedSubmissionResult(@fopen(resource_path('inc/xml/out/ccFeedSubmissionResult_' . $feedSubmissionId . '.xml'), 'w+'));
			 
		try {
			$response = $service->getFeedSubmissionResult($request);
			if ($response->isSetGetFeedSubmissionResultResult()) {
				$getFeedSubmissionResultResult = $response->getGetFeedSubmissionResultResult();
				if ($getFeedSubmissionResultResult->isSetContentMd5()) {
					$contentMd5 = $getFeedSubmissionResultResult->getContentMd5();
				}
			}
			if ($response->isSetResponseMetadata()) { 
				$responseMetadata = $response->getResponseMetadata();
				if ($responseMetadata->isSetRequestId()) {
					$requestID = $responseMetadata->getRequestId();
				}
			} 
			$responseHeaderMetadata = $response->getResponseHeaderMetadata();
			$feedResult = "Feed submission ID " . $feedSubmissionId . " submitted successfully.\nResponse details: " . $contentMd5 . ".\nRequest ID: " . $requestID . ".\nResponse Header Metadata: " . $responseHeaderMetadata . ".";
			$response = ["pass"=>true, $feedResult];
			return $response;
		} catch (\MarketplaceWebService_Exception $ex) {
			$errorMessage = 
				"Error exception: " . $ex->getMessage() . "." . 
				" Status code: " . $ex->getStatusCode() . "." . 
				" Error code: " . $ex->getErrorCode() . "." . 
				" Error type: " . $ex->getErrorType() . "." . 
				" Request ID: " . $ex->getRequestId() . "." .
				" XML " . $ex->getXML() . "." .
				" ResponseHeaderMetadata" . $ex->getResponseHeaderMetadata() . ".";
			$response = ["pass"=>false, $errorMessage];
			return $response;
		}
	}
}
