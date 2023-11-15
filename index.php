<?php 
error_reporting(E_ALL);
set_time_limit(600);

include("vendor/autoload.php");
use Symfony\Component\DomCrawler\Crawler;

class productScraper {
	
	private $baseURL = "https://luboil.ee/catalog/products/"; //IMP - SET baseURL here.
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
	  $path = 'json-data/data-'. $currStamp .'.json';
	  $jsonString = json_encode($this->scrapedData, JSON_PRETTY_PRINT);
	  $fp = fopen($path, 'w');
	  fwrite($fp, $jsonString);
	  fclose($fp);
	  
	  echo "<br><strong>Total results: ". count($this->scrapedData) ."</strong><br>";
	  echo "<br><strong>Check crawled data(json) here: scraper/json-data/data-".$currStamp.".json</strong><br>";
	  // print_r($this->scrapedData);
	  
	  
	  // TEMP FOR FACETWP
	  //  echo '<iframe id="theFrame" src="#" style="width:100%;height:100%;" frameborder="0"></iframe>';exit;
    }
	
	/**
	 * get page content & set crawler
	 */
	public function set_baseURL( $productsURL ) { echo $productsURL ."<br>"; 
	  $htmlContent = file_get_contents( $productsURL );
	  /***
		$dom1 = new DOMDocument();
		$dom1->loadHTML($htmlContent);    
		$node = $dom1->getElementById('shop-sidebar')->item(0);    
		$outerHTML = $node->ownerDocument->saveHTML($node);
		echo $outerHTML;exit;
	
	  if ( preg_match ( '/<div id="shop-sidebar"(.*?)<\/div>/s', $htmlContent, $matches ) ) {
		foreach ( $matches as $key => $match ) {
		  echo $key . ' => ' . htmlentities ( $match ) . '<br /><br />';
		}
	  } exit;
	  ***/
	  
	  $doc = new Crawler( $htmlContent );
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
	  
	/****
	  $this->pd_appliances = array();

	  echo $docObj->filter( '.sidebar-inner' )->html();exit;
	  
	  $this->appliances[] = $docObj->filter( '#shop-sidebar .facetwp-facet-product_appliances .facetwp-checkbox' )->each(function(Crawler $appliance, $i){
		  echo $appliance->innerText();
	  }); 
	  print_r($this->appliances);exit;
	 
	  $this->brands;
	  $this->categories;

	****/
	  
	  $pppCount = $docObj->filter( '.shop-container .products .product' );
	  if( $pppCount->count() > 0 ) {
		$pppCount->each(function (Crawler $product, $i){ 
		  $this->proLinks[$i] = $product->filter( '.box-text-products .product-title a' )->link()->getUri();

		  /****
		  $cn = $product->attr('class');
		  $this->pd_appliances[$i] = array_filter(explode(' ', $cn), function($class){
			if( str_contains($class, 'appliance-') ){
				return $class;
			}
		  }); //->matches('appliance-');
		  ****/
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
			                'appliance' 		=> '', 
			                'brand' 			=> '', 
			                'category' 			=> '',
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
										'appliance' 		=> '', 
										'brand' 			=> '', 
										'category' 			=> '',
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
