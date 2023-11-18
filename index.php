<?php 
error_reporting(E_ALL);
set_time_limit(600);

include("vendor/autoload.php");
use Symfony\Component\DomCrawler\Crawler;

class productScraper {
	
	private $baseURL = ""; //IMP - SET baseURL here.
	private $proLinks = array();
	private $scrapedData = array();

	//Product Variables
	private $appliances = array();
	private $brands = array();
	private $categories = array();
	private $pd_title, $pd_shortdesc, $pd_price, $pd_vol, $pd_categories, $pd_appliances, $pd_brands, $pd_url, $pd_variations;

	public function __construct() {
	  if( trim($this->baseURL) == "" ){ echo "<strong>Please set baseURL to proceed further...</strong>"; exit;}
	  echo "<strong>FETCHED PAGES: </strong><br>";
      $this->set_baseURL( $this->baseURL );
	  // Write in the file
	  $currStamp = date('YmdHis');
	  $dirPath = realpath( dirname( __FILE__ ) ) . '/json-data';
	  $filePath = $dirPath . '/data-'. $currStamp .'.json';
	  $jsonString = json_encode($this->scrapedData, JSON_PRETTY_PRINT);
	  if( !is_dir( $dirPath ) ){
		mkdir($dirPath, 0755);
	  }
	  $fp = fopen($filePath, 'w');
	  fwrite($fp, $jsonString);
	  fclose($fp);
	  
	  echo "<br><strong>Total results: ". count($this->scrapedData) ."</strong><br>";
	  echo "<br><strong>Check crawled data(json) here: scraper/json-data/data-".$currStamp.".json</strong><br>";
	  // print_r($this->scrapedData);
    }
	
	/**
	 * get page content & set crawler
	 */
	public function set_baseURL( $baseURL ) { echo $baseURL ."<br>"; 
	  $htmlContent = file_get_contents( $baseURL );
	  $doc = new Crawler( $htmlContent );

	  // Fetch all appliances, brands & categories
	  $doc->filter('script')->each(function(Crawler $script, $i){
		$aa = $script->text();
		if( str_contains( $aa, "FWP_JSON" ) ){
			$jsonData = html_entity_decode( substr( $aa, strlen( "window.FWP_JSON = " ) ) );
		    $jsonData = substr( $jsonData, 0, strpos( $jsonData, '; window.FWP_HTTP' ) );
			$jsonArr = json_decode( trim($jsonData), true);
			if( isset($jsonArr['preload_data']) && isset($jsonArr['preload_data']['facets']) ) {
				$domApp = new Crawler( $jsonArr['preload_data']['facets']['product_appliances'] );
				$this->appliances[] = $domApp->filter('.facetwp-checkbox')->each(function($appElem, $i){
					return $appElem->innerText();
				});
				$domBrands = new Crawler( $jsonArr['preload_data']['facets']['product_brands'] );
				$this->brands[] = $domBrands->filter('.facetwp-checkbox')->each(function($brandElem, $i){
					return $brandElem->innerText();
				});
				$domCat = new Crawler( $jsonArr['preload_data']['facets']['product_categories_en'] );
				$this->categories[] = $domCat->filter('.facetwp-checkbox')->each(function($catElem, $i){
					return $catElem->innerText();
				});
			}
		}
	  });

	  $this->get_curr_paged_products( $doc );
	}

	/**
	 * Check if the current page have next page of products
	 */
	public function check_for_next_page( $docObj ) {
	  $next = $docObj->filter( '.woocommerce-pagination .next' );
	  if( $next->count() > 0 ) {
	  	$this->baseURL = $next->link()->getUri();
		$this->set_baseURL( $this->baseURL );
	  }
	}
	
	/**
	 * Get paged products URI for process
	 */
	public function get_curr_paged_products( $docObj ) {
	  $this->proLinks = array();
  
	  $pppCount = $docObj->filter( '.shop-container .products .product' );
	  if( $pppCount->count() > 0 ) {
		$pppCount->each(function (Crawler $product, $i){ 
		  $pURL = $product->filter( '.box-text-products .product-title a' )->link()->getUri();
		  $this->proLinks[$i] = $pURL;
		  
		  // Fetch product appliances, brands & categories from class
		  $cn = $product->attr('class');
		  $this->pd_appliances[$pURL] = array_values( array_filter( array_map(function($class){
			if( str_contains( $class, 'appliance-' ) ){
			  return substr( $class, strlen( 'appliance-' ) );
			}
		  }, explode(' ', $cn)) ) );
		  $this->pd_brands[$pURL] = array_values( array_filter( array_map(function($class){
			if( str_contains( $class, 'brand-' ) ){
			  return substr( $class, strlen( 'brand-' ) );
			}
		  }, explode(' ', $cn)) ) );
		  $this->pd_categories[$pURL] = array_values( array_filter( array_map(function($class){
			if( str_contains( $class, 'product_cat-' ) ){
			  return substr( $class, strlen( 'product_cat-' ) );
			}
		  }, explode(' ', $cn)) ) );
		});
	  }
	  if( !empty($this->proLinks) ){
		$this->multi_curl_paged_products( $this->proLinks );
	  }
	  $this->check_for_next_page( $docObj );
	}
	
	/**
	 * Multi-cURL to paged products
	 */
	public function multi_curl_paged_products( $urls = array() ) {
	  $multiCurl = array();

	  $headers = array();
	  $headers[] = 'Pragma: no-cache';
	  $headers[] = 'Dnt: 1';
	  $headers[] = 'Accept-Encoding: gzip, deflate, br';
	  $headers[] = 'Accept-Language: en-US,en;q=0.8';
	  $headers[] = 'Upgrade-Insecure-Requests: 1';
	  $headers[] = 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.101 Safari/537.36';
	  $headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8';
	  $headers[] = 'Cache-Control: no-cache';
	  
	  $mh = curl_multi_init();
	  
	  foreach($urls as $k => $url ) {
		if( trim($url) ) {
	  		$multiCurl[$k] = curl_init();

	  		curl_setopt($multiCurl[$k], CURLOPT_URL, 			$url);
			curl_setopt($multiCurl[$k], CURLOPT_CUSTOMREQUEST, 	'GET');
	  	    curl_setopt($multiCurl[$k], CURLOPT_HEADER, 		1);
			curl_setopt($multiCurl[$k], CURLOPT_HTTPHEADER, 	$headers);
	  	    curl_setopt($multiCurl[$k], CURLOPT_SSL_VERIFYPEER,	0);
	  	    curl_setopt($multiCurl[$k], CURLOPT_SSL_VERIFYHOST,	0);
	  	    curl_setopt($multiCurl[$k], CURLOPT_ENCODING, 		"");
	  	    curl_setopt($multiCurl[$k], CURLOPT_RETURNTRANSFER,	1);
	  	    curl_setopt($multiCurl[$k], CURLOPT_FOLLOWLOCATION,	1);
	  	    curl_setopt($multiCurl[$k], CURLOPT_AUTOREFERER, 	1);
	  	    curl_setopt($multiCurl[$k], CURLOPT_CONNECTTIMEOUT,	60);
	  	    curl_setopt($multiCurl[$k], CURLOPT_TIMEOUT, 		60);
	  	    curl_setopt($multiCurl[$k], CURLOPT_MAXREDIRS, 		3);

			curl_multi_add_handle($mh, $multiCurl[$k]);
	  	}
	  }
	  
	  $index = null;
	  do {
		curl_multi_exec($mh,$index);
		curl_multi_select($mh, 30);
	  } while($index > 0);
	  
	  // Collect all data here and clean up
	  foreach ($multiCurl as $key => $request) {
		$cURLinfo = curl_getinfo($request);
	  	$documents[$key]['content'] = curl_multi_getcontent($request);
	  	$documents[$key]['productURL'] = $cURLinfo['url'];
	  	curl_multi_remove_handle($mh, $request); // Assuming we're being responsible about our resource management
	  	curl_close($request);                    // being responsible again, THIS MUST GO AFTER curl_multi_getcontent();
	  }
	  curl_multi_close($mh);

	  if( count($documents) > 0){
		foreach($documents as $k => $htmlContent) {
		  if( !empty( trim( $htmlContent['content'] ) ) ){
		    $objDoc = new Crawler( $htmlContent['content'] );
		    $this->pd_url =			$htmlContent['productURL'];
		    $this->pd_title = 		$objDoc->filter( '.product-main .product-info h1.product-title' )->text();
		    $this->pd_shortdesc = 	$objDoc->filter( '.product-main .product-info .product-short-description' )->text();

		    $variantObj = $objDoc->filter( '.product-main .product-info form.variations_form' );
		    if( $variantObj->count() > 0 ) {
			
		      $varData = $variantObj->attr('data-product_variations');
		      $this->pd_variations = !empty($varData) ? json_decode($varData, true) : array();
		      
		      $variantObjN = $variantObj->filter('.variations .variation-radio-button');
			  if( $variantObjN->count() > 0 ){
			    $variantObjN->each(function (Crawler $product, $i){
		          $this->pd_vol 	= substr($product->innerText(), 0, strpos( $product->innerText(), " ", strpos( $product->innerText(), " " )+1 ) );
		          $prodPrice	 	= $product->filter('.woocommerce-Price-amount.amount');
				  if( $prodPrice->count() > 0 ) {
					$this->pd_price = $prodPrice->innerText();  
				  } else {
					// $normalProd = $objDoc->filter( 'script[type="application/ld+json"]' )->text();
					// $normalProd = json_decode($normalProd, true);
					// $this->pd_price = $normalProd['offers'][0]['price'];
					$this->pd_price = '';
				  }

			      if( !empty($this->pd_variations) ) {
			        foreach( $this->pd_variations as $v ){
			      	  if( $v['attributes']['attribute_pa_volume'] === strtolower(str_replace(' ', '-', $this->pd_vol)) ){
			      	    $this->scrapedData[] = array( 
			                'title' 			=> $this->pd_title,
			                'shortDesc' 		=> $this->pd_shortdesc,
			                'volume_capacity' 	=> $this->pd_vol,
			                'price' 			=> $this->pd_price,
			                'sku' 				=> $v['sku'],
			                'ean' 				=> $v['ean'],
			                'appliance' 		=> $this->pd_appliances[$this->pd_url], 
			                'brand' 			=> $this->pd_brands[$this->pd_url], 
			                'category' 			=> $this->pd_categories[$this->pd_url],
			                'productURL' 		=> $this->pd_url,
							'variationData'		=> $v
		                  );
			      	  }
			        }
			      }

		        } );
		      }
		    
		    } else { //For normal products
				$normalProd = $objDoc->filter( 'script[type="application/ld+json"]' )->text();
				$normalProd = json_decode($normalProd, true);
				$this->scrapedData[] = array( 
										'title' 			=> $this->pd_title,
										'shortDesc' 		=> $this->pd_shortdesc,
										'volume_capacity' 	=> '',
										'price' 			=> $normalProd['offers'][0]['price'],
										'sku' 				=> $normalProd['sku'],
										'ean' 				=> '',
										'appliance' 		=> $this->pd_appliances[$this->pd_url], 
										'brand' 			=> $this->pd_brands[$this->pd_url], 
										'category' 			=> $this->pd_categories[$this->pd_url],
										'productURL' 		=> $this->pd_url,
										'variationData'		=> null
									);
			}
		    unset($objDoc);
		  }
		}
	  }

	}
}

if ( class_exists( 'productScraper' ) ) {
  new productScraper;
};
?>
