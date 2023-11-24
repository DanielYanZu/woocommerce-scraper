<?php
error_reporting(E_ALL);
set_time_limit(600);

include("vendor/autoload.php");

use Symfony\Component\DomCrawler\Crawler;

class TheScraper
{
	/**
	 * Intial values
	 */
	private string $mainBaseUrl = "https://luboil.ee/et/catalog/tooted/"; // !! SET $mainBaseUrl here.
	private string $mainLocale = 'et';
	private array $translateLocales = ['en'];

	private string $timestamp;

	/**
	 * Dynamic values
	 */
	private string $currentScrapedUrl;
	private array $products = [];

	//Product Variables
	private array $appliances, $brands, $categories;

	public function __construct()
	{
		$this->timestamp = date('YmdHis');

		if (trim($this->mainBaseUrl) == "") {
			echo "<strong>Please set baseURL to proceed further...</strong>\n";
			exit;
		}

		echo "<strong>FETCHED PAGES: </strong>\n";

		$this->currentScrapedUrl = $this->mainBaseUrl;

		$this->scrapePageForProductUrls($this->currentScrapedUrl);

		$this->makeItHappen();
	}

	/**
	 * get page content & set crawler
	 */
	public function scrapePageForProductUrls(string $currentScrapedUrl)
	{
		echo sprintf("Collecting product urls from: %s", $currentScrapedUrl);

		$htmlContent = file_get_contents($currentScrapedUrl);

		$currentPageNode = new Crawler($htmlContent);

		// Fetch all appliances, brands & categories
		if (empty($this->appliances) || empty($this->brands) || empty($this->categories)) {
			$currentPageNode->filter('script')->each(function (Crawler $script, $i) {
				$aa = $script->text();
				if (str_contains($aa, "FWP_JSON")) {
					$jsonData = html_entity_decode(substr($aa, strlen("window.FWP_JSON = ")));
					$jsonData = substr($jsonData, 0, strpos($jsonData, '; window.FWP_HTTP'));
					$jsonArr = json_decode(trim($jsonData), true);
					if (isset($jsonArr['preload_data']) && isset($jsonArr['preload_data']['facets'])) {
						$domApp = new Crawler($jsonArr['preload_data']['facets']['product_appliances']);
						$domApp->filter('.facetwp-checkbox')->each(function ($appElem, $i) {
							$this->appliances[$this->create_slug($appElem->innerText())] = $appElem->innerText();
						});
						$domBrands = new Crawler($jsonArr['preload_data']['facets']['product_brands']);
						$domBrands->filter('.facetwp-checkbox')->each(function ($brandElem, $i) {
							$this->brands[$this->create_slug($brandElem->innerText())] = $brandElem->innerText();
						});
						$domCat = new Crawler($jsonArr['preload_data']['facets']['product_categories_' . $this->mainLocale]);
						$domCat->filter('.facetwp-checkbox')->each(function ($catElem, $i) {
							$this->categories[$this->create_slug($catElem->innerText())] = $catElem->innerText();
						});
					}
				}
			});
		}

		$this->scrapeCurrentPageForProducts($currentPageNode);
	}

	/**
	 * Get paged products URI for process
	 */
	public function scrapeCurrentPageForProducts(Crawler $currentPageNode)
	{
		$currentPageProductNodes = $currentPageNode->filter('.shop-container .products .product');

		if ($currentPageProductNodes->count() > 0) {
			$currentPageProductNodes->each(function (Crawler $productCardNode, $i) {
				$productCardClass = $productCardNode->attr('class');

				$productClasses = explode(' ', $productCardClass);

				$productId = $productCardNode->filter('.primary.button')->attr('data-product_id');
				$productSku = $productCardNode->filter('.primary.button')->attr('data-product_sku');
				$productUrl = $productCardNode->filter('.box-text-products .product-title a')->link()->getUri();

				$this->products[$productId]['url'][$this->mainLocale] = $productUrl;
				$this->products[$productId]['sku'] = $productSku;

				// Fetch product appliances, brands & categories from class

				$this->products[$productId]['appliances'] = array_values(
					array_filter(
						array_map(
							function ($class) {
								if (str_contains($class, 'appliance-')) {
									$termSlug = substr($class, strlen('appliance-'));
									return [
										$termSlug => isset($this->appliances[$termSlug])
											? $this->appliances[$termSlug]
											: $termSlug
									];
								}
							},
							$productClasses
						)
					)
				);

				$this->products[$productId]['brands'] = array_values(
					array_filter(
						array_map(
							function ($class) {
								if (str_contains($class, 'brand-')) {
									$termSlug = substr($class, strlen('brand-'));
									return [
										$termSlug => isset($this->brands[$termSlug])
											? $this->brands[$termSlug]
											: $termSlug
									];
								}
							},
							$productClasses
						)
					)
				);

				$this->products[$productId]['categories'] = array_values(
					array_filter(
						array_map(
							function ($class) {
								if (str_contains($class, 'product_cat-')) {
									$termSlug = substr($class, strlen('product_cat-'));
									return [
										$termSlug => isset($this->categories[$termSlug])
											? $this->categories[$termSlug]
											: $termSlug
									];
								}
							},
							$productClasses
						)
					)
				);
			});
		}

		echo sprintf(" >>> now %s\n", count($this->products));

		$nextPageLinkNode = $currentPageNode->filter('.woocommerce-pagination .next');

		if ($nextPageLinkNode->count() > 0) {
			$this->currentScrapedUrl = $nextPageLinkNode->link()->getUri();
			$this->scrapePageForProductUrls($this->currentScrapedUrl);
		}

		echo "Product urls collected.\n";
	}

	protected function makeItHappen()
	{
		$mainLocaleBatch = new LocaleScraper($this->products, $this->mainLocale);
		$mainLocaleData = array_values($mainLocaleBatch->scrape()->getData());
		$this->writeToJson($mainLocaleData, $this->mainLocale);

		$this->products = $mainLocaleBatch->getProducts();

		foreach ($this->translateLocales as $translateLocale) { // Iterate additional locale
			$otherLocaleBatch = new LocaleScraper($this->products, $translateLocale);
			$otherLocaleBatch = $otherLocaleBatch->scrape();

			$otherLocaleData = array_values($otherLocaleBatch->getData());

			$this->writeToJson($otherLocaleData, $translateLocale);

			$this->products = $otherLocaleBatch->getProducts();
		}
	}

	/**
	 * Create slug for random strings
	 */
	public function create_slug($string)
	{
		$string = strtolower(trim($string));
		$string = str_replace('.', '-', $string);
		$string = preg_replace('/[^a-z0-9- ]/', '', $string);
		$string = preg_replace('/\s+/', ' ', $string);
		$slug = str_replace(' ', '-', $string);
		return $slug;
	}

	public function writeToJson(array $scrapedProducts, string $locale)
	{
		// Write in the file

		$dirPath = realpath(dirname(__FILE__)) . '/json-data';

		$filePath = sprintf(
			'%s/data-%s-%s.json',
			$dirPath,
			$this->timestamp,
			$locale
		);

		$jsonString = json_encode($scrapedProducts, JSON_PRETTY_PRINT);

		if (!is_dir($dirPath)) {
			mkdir($dirPath, 0755);
		}

		$fp = fopen($filePath, 'w');
		fwrite($fp, $jsonString);
		fclose($fp);

		echo sprintf(
			"Total results: %s\n",
			count($scrapedProducts)
		);
	}
}

class LocaleScraper
{
	private array $products;
	private string $locale;

	public function __construct(array $productsData, string $locale)
	{
		$this->products = $productsData;
		$this->locale = $locale;
	}

	public function scrape(): self
	{
		$this->multiCurlProductPages();

		return $this;
	}

	public function getData(): array
	{
		return array_map(function ($product) {
			return $product['data'][$this->locale];
		}, $this->products);
	}

	public function getProducts(): array
	{
		return $this->products;
	}

	/**
	 * Multi-cURL to paged products
	 */
	protected function multiCurlProductPages()
	{
		echo sprintf(
			"Multi curling all product urls..."
		);

		$multiCurl = [];

		$headers = [
			'Pragma: no-cache',
			'Dnt: 1',
			'Accept-Encoding: gzip, deflate, br',
			'Accept-Language: en-US,en;q=0.8',
			'Upgrade-Insecure-Requests: 1',
			'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.101 Safari/537.36',
			'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
			'Cache-Control: no-cache',
		];

		$multiCurlHandle = curl_multi_init();

		foreach ($this->products as $productId => $productData) {
			if (!isset($productData['url'][$this->locale])) {
				continue;
			}
			$productUrl = $productData['url'][$this->locale];

			if (trim($productUrl)) {
				$multiCurl[$productId] = curl_init();

				curl_setopt($multiCurl[$productId], CURLOPT_URL, 			$productUrl);
				curl_setopt($multiCurl[$productId], CURLOPT_CUSTOMREQUEST, 	'GET');
				curl_setopt($multiCurl[$productId], CURLOPT_HEADER, 		1);
				curl_setopt($multiCurl[$productId], CURLOPT_HTTPHEADER, 	$headers);
				curl_setopt($multiCurl[$productId], CURLOPT_SSL_VERIFYPEER,	0);
				curl_setopt($multiCurl[$productId], CURLOPT_SSL_VERIFYHOST,	0);
				curl_setopt($multiCurl[$productId], CURLOPT_ENCODING, 		"");
				curl_setopt($multiCurl[$productId], CURLOPT_RETURNTRANSFER,	1);
				curl_setopt($multiCurl[$productId], CURLOPT_FOLLOWLOCATION,	1);
				curl_setopt($multiCurl[$productId], CURLOPT_AUTOREFERER, 	1);
				curl_setopt($multiCurl[$productId], CURLOPT_CONNECTTIMEOUT,	60);
				curl_setopt($multiCurl[$productId], CURLOPT_TIMEOUT, 		60);
				curl_setopt($multiCurl[$productId], CURLOPT_MAXREDIRS, 		3);

				curl_multi_add_handle($multiCurlHandle, $multiCurl[$productId]);
			}
		}

		$index = null;
		do {
			curl_multi_exec($multiCurlHandle, $index);
			curl_multi_select($multiCurlHandle, 30);
		} while ($index > 0);

		$documents = [];

		// Collect all data here and clean up
		foreach ($multiCurl as $productId => $request) {
			$cURLinfo = curl_getinfo($request);

			$documents[$productId] = curl_multi_getcontent($request);

			curl_multi_remove_handle($multiCurlHandle, $request); // Assuming we're being responsible about our resource management
			curl_close($request); // being responsible again, THIS MUST GO AFTER curl_multi_getcontent();
		}

		curl_multi_close($multiCurlHandle);

		if (count($documents) > 0) {
			foreach ($documents as $productId => $productPageHtml) {
				$this->products[$productId]['html'][$this->locale] = $productPageHtml;
			}
		} else {
			echo "BIG PROBLEM!\n";
			return;
		}

		echo sprintf(
			"COMPLETE. Curled %s documents for %s locale.\n",
			count($documents),
			$this->locale
		);

		echo sprintf(
			"Scraping for products: "
		);

		foreach ($this->products as $productId => $productData) {
			$scrapedProduct = new ScrapedProduct($productId, $productData, $this->locale);
			$this->products[$productId]['data'][$this->locale] = $scrapedProduct->getData();

			$otherUrls = $scrapedProduct->getUrls();
			if (count($otherUrls) > 1) {
				foreach ($otherUrls as $locale => $url) {
					$this->products[$productId]['url'][$locale] = $url;
				}
			}

			echo sprintf(
				"%s... ",
				$productId
			);
		}

		echo sprintf(
			"ALL SCRAPED.\n"
		);
	}
}

class ScrapedProduct
{
	public $id;
	public $product;
	public $locale;

	protected $productData;
	protected $localeUrls = [];
	protected $documents = [];

	private $pd_variations;

	public function __construct($productId, $product, $locale)
	{
		$this->id = $productId;
		$this->product = $product;
		$this->locale = $locale;

		$this->crawl();
	}

	public function getData()
	{
		return $this->productData;
	}

	public function getUrls()
	{
		return $this->localeUrls;
	}

	public function getDocuments()
	{
		return $this->documents;
	}

	protected function crawl()
	{
		if (empty($this->product['html'][$this->locale])) {
			sprintf(
				"ERROR: product %s html for %s locale empty!\n",
				$this->id,
				$this->locale,
			);

			$this->productData = null;

			return;
		}

		$objDoc = new Crawler($this->product['html'][$this->locale]);

		$productTitle = 		$objDoc->filter('.product-main .product-info h1.product-title')->text();
		$productShortDesc = 	$objDoc->filter('.product-main .product-info .product-short-description')->text();
		$productType =			$objDoc->filter('.shop-container > .product')->matches('.product-type-variable')
			? "variable"
			: ($objDoc->filter('.shop-container > .product')->matches('.product-type-simple')
				? "simple"
				: "");

		$image = null;

		try {
			$imageEl = 		$objDoc->filter('.woocommerce-product-gallery__image .wp-post-image');
			$imageUrl =		$imageEl->attr('src');
			$imageWidth =	$imageEl->attr('width');
			$imageHeight =	$imageEl->attr('height');
			$image = 		str_replace(sprintf('-%sx%s', $imageWidth, $imageHeight), "", $imageUrl);
		} catch (\Throwable $th) {
			//throw $th;
		}

		$productImage = $image ? $image : '';

		$product = [
			'url' 				=> $this->product['url'][$this->locale],
			'id'				=> $this->id,
			'locale'			=> $this->locale,

			'type'				=> $productType,
			'title' 			=> $productTitle,
			'image_url'			=> $productImage,
			'short_desc' 		=> $productShortDesc,
			// 'appliance' 		=> $this->pd_appliances[$this->pd_url],
			// 'appliances'		=> implode('|', $this->pd_appliances[$this->pd_url]),
			// 'brand' 			=> $this->pd_brands[$this->pd_url],
			// 'brands'			=> implode('|', $this->pd_brands[$this->pd_url]),
			// 'category' 			=> $this->pd_categories[$this->pd_url],
			// 'categories'		=> implode('|', $this->pd_categories[$this->pd_url]),

		];

		foreach (['appliances', 'brands', 'categories'] as $key) {
			if (isset($this->product[$key]) && count($this->product[$key]) > 0) {
				$items = [];
				foreach ($this->product[$key] as $values) {
					foreach ($values as $termKey => $termValue) {
						$items[] = $termKey;
					}
				}
				$product[$key] = implode('|', $items);
			}
		}

		$variantObj = $objDoc->filter('.product-main .product-info form.variations_form');
		if ($variantObj->count() > 0) {
			$product['variations'] = [];

			$varData = $variantObj->attr('data-product_variations');
			$this->pd_variations = !empty($varData) ? json_decode($varData, true) : [];

			$product['variations'] = $this->pd_variations;
		} else { //For normal products
			$product['variations'] = null;

			$normalProd = $objDoc->filter('script[type="application/ld+json"]')->text();
			$normalProd = json_decode($normalProd, true);

			$product = array_merge($product, [
				'volume_capacity' 	=> '',
				'price' 			=> $normalProd['offers'][0]['price'],
				'sku' 				=> $normalProd['sku'],
				'ean' 				=> '',
			]);
		}

		$documentsNode = $objDoc->filter('.product-main .product-info .woocommerce-product-documents');
		if ($documentsNode->count() > 0) {
			$documentLinkNodes = $documentsNode->filter('a');
			if ($documentLinkNodes->count() > 0) {
				$documentLinkNodes->each(function (Crawler $documentLinkNode) {
					$documentUrl = $documentLinkNode->attr('href');
					$documentTitle = $documentLinkNode->text();

					$this->documents[] = [
						'title' => $documentTitle,
						'url' => $documentUrl
					];
				});

				$product['documents'] = $this->documents;
			}
		}

		$wpmlMenuItemNode = $objDoc->filter('#header .nav.header-nav > .menu-item.wpml-ls-item');
		if ($wpmlMenuItemNode->count() > 0) {
			// $wpmlMenuItemId = $wpmlMenuItemNode->attr('id');
			// $start = substr($wpmlMenuItemId, 0, (strlen($wpmlMenuItemId) - strlen($this->locale)));

			$otherLocales = $wpmlMenuItemNode->filter('.wpml-ls-item');
			if ($otherLocales->count() > 0) {
				$otherLocales->each(function (Crawler $localeItemNode) {
					$localeItemId = $localeItemNode->attr('id');
					$otherLocale = substr($localeItemId, -2);
					$otherLocaleUrl = $localeItemNode->filter('a')->link()->getUri();
					$this->localeUrls[$otherLocale] = $otherLocaleUrl;

					// echo sprintf(
					// 	"Found translated url: %s\n",
					// 	$otherLocaleUrl
					// );
				});
			} else {
				throw new Exception('PROBLEM 2');

				echo sprintf(
					"No other locales found for %s.\n",
					$this->id
				);
			}
		} else {
			throw new Exception('PROBLEM 1');
		}

		$this->productData = $product;
	}
}

if (class_exists('TheScraper')) {
	new TheScraper;
};
