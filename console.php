<?php
ob_implicit_flush(true);
ob_start();
require_once "./vendor/autoload.php";

use Console\Decorate;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\SessionCookieJar;
use Psr\Http\Message\ResponseInterface;

class ShoesDotCom
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var SessionCookieJar
     */
    private $cookie;

    /**
     * @var array
     */
    private $availableCoupon = ["SHOESDOTCOM", "25SEPT", "FALL18"];

    private $productUrl;
    private $size;
    private $width;
    private $color;
    private $userID;

    public function __construct(string $productUrl, float $size, string $width, string $color)
    {
        $this->cookie = new SessionCookieJar("shoes.com_cookie_".$this->milliseconds());
        $this->client = new Client([
            'cookies' => $this->cookie
        ]);
        $this->productUrl = $productUrl;
        $this->size = $size;
        $this->width = $width;
        $this->color = $color;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function process(): bool
    {
        $this->display("Start to fetch product details", "green");
        $startTime = $this->milliseconds();
        $response = $this->client->get($this->productUrl);
        if ($response->getStatusCode() != 200) {
            $this->display("Failed to fetch product page: {$response->getReasonPhrase()}", "red");
            return false;
        }
        $body = $response->getBody();
        $matches = [];
        preg_match_all("#productCollection\.addProductObject\((\d)+\,([^;]+)\);#", $body, $matches);
        if (empty($matches) || !isset($matches[2])) {
            $this->display("Failed to get product object", "red");
            return false;
        }

        $productObject = json_decode($matches[2][0], TRUE);
        if (empty($productObject)) {
            $this->display("Product is not valid", "red");
            return false;
        }

        $sizeIdx = FALSE;
        foreach ($productObject['sizes'] as $idx => $criteria) {
            if (floatval($criteria[0]) == $this->size) {
                $sizeIdx = $idx;
                break;
            }
        }
        if ($sizeIdx === FALSE) {
            $this->display("Size is not valid", "red");
            return false;
        }

        $widthIdx = FALSE;
        foreach ($productObject['widths'] as $idx => $criteria) {
            if (strtolower($criteria[0]) == strtolower($this->width)) {
                $widthIdx = $idx;
                break;
            }
        }
        if ($widthIdx === FALSE) {
            $this->display("Width is not valid", "red");
            return false;
        }

        $colorIdx = FALSE;
        foreach ($productObject['colors'] as $idx => $color) {
            if (strpos(strtolower($color['name']), strtolower($this->color)) !== FALSE) {
                $colorIdx = $idx;
                break;
            }
        }
        if ($colorIdx === FALSE) {
            $this->display("Color is not valid", "red");
            return false;
        }

        $productStyleCode = $productObject['styleCode'];
        try {
            $productSku = $productObject['skus'][$sizeIdx][$widthIdx][$colorIdx];
        } catch (\Throwable $e) {
            $productSku = 0;
        }

        if (!isset($productSku) || $productSku == 0) {
            $this->display("Product with your criteria is out of stock", "red");
            return false;
        }

        $this->display("Request api endpoint to get user cookie", "yellow");
        $this->requestApi("GET", "https://www.shoes.com/cart-checkout/api");

        $userCookie = $this->cookie->getCookieByName('user');
        if (empty($userCookie)) {
            $this->display("Failed to get user cookie", "red");
            return false;
        }
        $this->userID = explode("||", urldecode($userCookie->getValue()))[0];

        $this->display("Adding item to cart ...", "yellow");
        $this->requestApi("PUT", "https://www.shoes.com/cart-checkout/api/legacy/cart", [
            'body' => json_encode([
                'quantity' => 1,
                'skuCode'  => strval($productSku),
                'styleId'  => strval($productStyleCode)
            ])
        ]);

        $bestSaving = [];
        foreach ($this->availableCoupon as $code) {
            $result = $this->tryCoupons($code);
            if (empty($bestSaving) || ($result['success'] && $bestSaving['savingAmount'] < $result['savingAmount'])) {
                $bestSaving = $result;
            }
        }

        $timeTaken = $this->milliseconds() - $startTime;
        $this->display("Completed process add item to cart and try coupon code", "green");
        $this->display("------------------------", "green");
        $this->display("
            Product\t\t: {$productObject['name']} \n
            Price\t\t: {$bestSaving['subTotal']} \n
            Coupon Applied\t: {$bestSaving['couponCode']} \n
            Discounted Amount\t: {$bestSaving['savingAmount']} \n
            Discounted Percent\t: {$bestSaving['savingPercent']}% \n
            Time taken\t\t: {$timeTaken}ms\n
        ", "green");
        return true;
    }

    private function milliseconds()
    {
        $mt = explode(' ', microtime());
        return ((int)$mt[1]) * 1000 + ((int)round($mt[0] * 1000));
    }

    /**
     * @param string $couponCode
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function tryCoupons(string $couponCode): array
    {
        $this->display("Trying apply coupon \"{$couponCode}\"", "yellow");

        $uri = "https://www.shoes.com/cart-checkout/api/users/{$this->userID}/orders/open/default/promotion";

        try {
            $this->requestApi("PUT", $uri, [
                'body' => json_encode([
                    'promotionId' => $couponCode
                ])
            ]);
        } catch (\Throwable $e) {
            $this->display("Trying apply coupon failed: {$e->getMessage()}", "red");
        }

        $cartItems = $this->getCartDetails();
        $subTotal = floatval(str_replace("$", "", $cartItems['summary']['subtotal']));
        $result = [
            'success'       => false,
            'subTotal'      => $subTotal,
            'savingAmount'  => 0.00,
            'savingPercent' => 0,
            'couponCode'    => '',
        ];
        if (isset($cartItems['summary']['promotionApplied'])) {
            $savingsAmount = floatval(str_replace("$", "", $cartItems['summary']['promotionApplied']));
            $result = [
                'success'       => true,
                'subTotal'      => $subTotal,
                'savingAmount'  => $savingsAmount,
                'savingPercent' => round($savingsAmount / $subTotal * 100, 2),
                'couponCode'    => $couponCode,
            ];
        }

        try {
            $this->requestApi("DELETE", $uri);
        } catch (\Throwable $e) {
            $this->display("Trying remove coupon failed: {$e->getMessage()}", "red");
        }

        return $result;
    }

    /**
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getCartDetails() : array
    {
        $cartDetails = $this->requestApi("GET", "https://www.shoes.com/cart-checkout/api/users/{$this->userID}/orders/open/default")->getBody()->getContents();
        $cartDetails = json_decode($cartDetails, TRUE);
        return $cartDetails['orderSummary']['order'];
    }

    /**
     * @param $method
     * @param $url
     * @param array $options
     * @return ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Exception
     */
    private function requestApi($method, $url, $options = []): ResponseInterface
    {
        $response = $this->client->request($method, $url, array_merge_recursive([
            'headers' => [
                'Accept'           => 'application/json, text/javascript, */*; q=0.01',
                'Content-Type'     => 'application/vnd.com.shoebuy.v1+json',
                'Host'             => 'www.shoes.com',
                'Origin'           => 'https://www.shoes.com',
                'User-Agent'       => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36',
                'X-Requested-With' => 'XMLHttpRequest'
            ]
        ], $options));

        if ($response->getStatusCode() != 200) {
            $this->display("Failed to request: {$method} {$url}. Status Code: {$response->getStatusCode()}. Trace: {$response->getReasonPhrase()}\n Body: {$response->getBody()}", "red");
            throw new \Exception("Failed to request API");
        }
        return $response;
    }

    /**
     * Echos the given message with the given colors on one line
     *
     * @param string $message
     * @param string $colors
     */
    public function display(string $message, string $colors = '')
    {
        echo '[' . (new \DateTime)->format('Y-m-d H:i:s') . '] '. Decorate::color($message, $colors) . PHP_EOL;
        ob_flush();
    }
}

$products = [
    [
        "http://click.linksynergy.com/link?id=SHQDIhAtOKQ&offerid=401480.12468845&type=15&murl=http%3A%2F%2Ftracking.searchmarketing.com%2Fclick.asp%3Faid%3D530007130005982624",
        6.5,
        "m",
        "dark brown"
    ],
    [
        "http://click.linksynergy.com/link?id=SHQDIhAtOKQ&offerid=401480.7381588&type=15&murl=http%3A%2F%2Ftracking.searchmarketing.com%2Fclick.asp%3Faid%3D530007130005854452",
        8,
        "w",
        "brown"
    ],
    [
        "http://click.linksynergy.com/link?id=SHQDIhAtOKQ&offerid=401480.12468784&type=15&murl=http%3A%2F%2Ftracking.searchmarketing.com%2Fclick.asp%3Faid%3D530007130005982456",
        8.5,
        "w",
        "dark brown"
    ],
    [
        "https://www.shoes.com/naturalizer-dora-bootie/811698/1760282",
        6.5,
        "m",
        "black leather"
    ]
];

foreach ($products as $product) {
    try {
        (new ShoesDotCom($product[0], $product[1], $product[2], $product[3]))->process();
    } catch (\Throwable $e) {
        var_dump($e->getMessage());
    }
}