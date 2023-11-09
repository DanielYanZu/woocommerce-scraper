<?php 
error_reporting(E_ALL);
set_time_limit(600);

include("vendor/autoload.php");
use Symfony\Component\DomCrawler\Crawler;

class productScraper {
	
	private $baseURL = "https://luboil.ee/catalog/products/;";
	private $doc, $proLinks;
	private $nextPagination = false;

	public function __construct() {
      $this->set_baseURL( $this->baseURL );
    }
	
	/**
	 * get page content & set crawler
	 */
	public function set_baseURL( $productsURL ) {
	  $htmlContent = file_get_contents( $productsURL );
	  $this->doc = new Crawler( $htmlContent );
	  $this->check_for_next_page( $this->doc );
	  $this->get_curr_paged_products( $this->doc );
	}

	/**
	 * Check if the current page have next page of products
	 */
	public function check_for_next_page( $docObj ) {
	  $next = $docObj->filter( '.woocommerce-pagination .next' );
	  if( $next->count() > 0 ) {
	  	$this->baseURL = $next->link()->getUri();
	  	$nextPagination = true;
	  } else {
	  	$nextPagination = false;
	  }
	}
	
	/**
	 * Get paged products URI for process
	 */
	public function get_curr_paged_products( $docObj ) {
	  $pppCount = $docObj->filter( '.shop-container .products .product' );
	  if( $pppCount->count() ) {
		  $this->proLinks = array();
		$pppCount->each(function (Crawler $product, $i){ 
		  $this->proLinks[] = $product->filter( '.box-text-products .product-title a' )->link()->getUri();
		});
		print_r($this->proLinks);
	  }
	}
}

if (class_exists('productScraper')) {
  new productScraper;
};
?>
