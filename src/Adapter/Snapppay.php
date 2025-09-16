<?php
declare(strict_types=1);

namespace PhpMonsters\Larapay\Adapter;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use PhpMonsters\Larapay\Adapter\Zarinpal\Exception;
use PhpMonsters\Larapay\Models\LarapayTransaction;
use PhpMonsters\Log\Facades\XLog;

/**
 * Class Snapppay
 * @package PhpMonsters\Larapay\Adapter
 */
class Snapppay extends AdapterAbstract implements AdapterInterface
{

    /**
     * @var Client
     */
    private $client;

    protected $endPoint = 'https://fms-gateway-staging.apps.public.okd4.teh-1.snappcloud.io/api/online';
    protected $sandboxEndPoint = 'https://fms-gateway-staging.apps.public.okd4.teh-1.snappcloud.io/api/online';


    public $reverseSupport = false;


    /**
     * @return string
     * @throws Exception
     * @throws \PhpMonsters\Larapay\Adapter\Exception
     */
    protected function generateForm(): string
    {
        $authority = $this->getOAuthToken();

        $form = view('larapay::zarinpal-form', [
            'endPoint' => strtr($this->getEndPoint(), ['{authority}' => $authority]),
            'submitLabel' => !empty($this->submit_label) ? $this->submit_label : trans("larapay::larapay.goto_gate"),
            'autoSubmit' => boolval($this->auto_submit),
        ]);

        return $form->__toString();
    }

    /**
     * @return array
     * @throws Exception
     * @throws \PhpMonsters\Larapay\Adapter\Exception
     */
    public function formParams(): array
    {

        $token = $this->getOAuthToken();


        $paymentData = $this->getSaleData();


        $snapp_pay_response = $this->createSnappPayPayment($token, $paymentData);


        $this->transaction->update([
            'additional_data' => [
                'payment_token' => $snapp_pay_response['response']['paymentToken']
            ]
        ]);


        if ($snapp_pay_response['successful'] !== true) {
            throw new \Exception("خطا در دریافت توکن");
        }


        return [
            'endPoint' => $snapp_pay_response['response']['paymentPageUrl'],
        ];
    }

    /**
     * @return bool
     * @throws Exception
     * @throws \PhpMonsters\Larapay\Adapter\Exception
     */
    protected function verifyTransaction(): bool
    {
        if ($this->getTransaction()->checkForVerify() == false) {
            throw new Exception('larapay::larapay.could_not_verify_payment');
        }

        $this->checkRequiredParameters([
            'transactionId',
            'amount',
            'state',
        ]);


        $token = $this->getOAuthToken();


        $response = $this->client->post(
            $this->getEndPoint() . '/payment/v1/verify',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'paymentToken' => json_decode($this->transaction->additional_data, true)['payment_token']
                ]),
            ]
        );

        if ($response->getStatusCode() != 200) {
            throw new \Exception("خطا در دریافت تایید تراکنش");
        }

        $responseBody = $response->getBody()->getContents();
        $tokenData = json_decode($responseBody, true);

        if (!$tokenData['successful']) {
            throw new \Exception("تراکنش ناموفق");
        }


        $response = $this->client->post(
            $this->getEndPoint() . '/payment/v1/settle',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'paymentToken' => json_decode($this->transaction->additional_data, true)['payment_token']
                ]),
            ]
        );

        if ($response->getStatusCode() != 200) {
            throw new \Exception("خطا در دریافت تایید تراکنش");
        }

        $responseBody = $response->getBody()->getContents();
        $tokenData = json_decode($responseBody, true);

        if (!$tokenData['successful']) {
            throw new \Exception("تراکنش ناموفق");
        }

        return true;

    }

    /**
     * @return bool
     */
    public function canContinueWithCallbackParameters(): bool
    {
        if ($this->state == "OK") {
            return true;
        }

        return false;
    }

    public function getGatewayReferenceId(): string
    {
        $this->checkRequiredParameters([
            'transactionId',
        ]);

        return strval($this->transactionId);
    }

    /**
     * متد جدید برای ایجاد درخواست پرداخت با SnappPay
     */
    public function createSnappPayPayment($accessToken, $paymentData)
    {
        $json = [
            'amount' => $paymentData['amount'],
            'cartList' => $paymentData['cartList'],
            'discountAmount' => $paymentData['discountAmount'] ?? 0,
            'externalSourceAmount' => $paymentData['externalSourceAmount'] ?? 0,
            'mobile' => $paymentData['mobile'],
            'paymentMethodTypeDto' => $paymentData['paymentMethodTypeDto'],
            'returnURL' => $paymentData['returnURL'],
            'transactionId' => $paymentData['transactionId'],
        ];


        $response = $this->client->post(
            $this->getEndPoint() . '/payment/v1/token',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $json,
            ]
        );


        if ($response->getStatusCode() != 200) {
            throw new \Exception("خطا در ایجاد درخواست پرداخت: " . $response->getStatusCode());
        }

        $responseBody = $response->getBody()->getContents();

        \Log::warning("created", compact('responseBody', 'json'));


        return json_decode($responseBody, true);
    }


    public function updateSnappPayPayment($accessToken, $paymentData, $paymentToken)
    {


        try {
            $json = [
                'amount' => $paymentData['amount'],
                'cartList' => $paymentData['cartList'],
                'discountAmount' => $paymentData['discountAmount'] ?? 0,
                'externalSourceAmount' => $paymentData['externalSourceAmount'] ?? 0,
                'mobile' => $paymentData['mobile'],
                'paymentMethodTypeDto' => $paymentData['paymentMethodTypeDto'],
                'paymentToken' => $paymentToken,
            ];
            $response = $this->client->post(
                $this->getEndPoint() . '/payment/v1/update',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $json,
                ]
            );

            if ($response->getStatusCode() != 200) {
                throw new \Exception("خطا در ایجاد درخواست پرداخت: " . $response->getStatusCode());
            }

            $responseBody = $response->getBody()->getContents();

            \Log::warning("updated", compact('responseBody', 'json'));


            return json_decode($responseBody, true);

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                $errorResponse = $e->getResponse()->getBody()->getContents();
                throw new \Exception("خطا در درخواست پرداخت: " . $errorResponse);
            }
            throw new \Exception("خطا در ارتباط با سرور پرداخت: " . $e->getMessage());
        }
    }

    /**
     * متد برای دریافت توکن دسترسی OAuth
     */
    public function getOAuthToken()
    {

        $this->client = new Client(config('larapay.requuest_config'));

        try {
            $response = $this->client->post(
                $this->getEndPoint() . '/v1/oauth/token',
                [
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode(config('larapay.snapppay.client_id') . ':' . config('larapay.snapppay.client_secret')),
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'form_params' => [
                        'grant_type' => 'password',
                        'scope' => 'online-merchant',
                        'username' => config('larapay.snapppay.username'),
                        'password' => config('larapay.snapppay.password'),
                    ],
                ]
            );

            if ($response->getStatusCode() != 200) {
                throw new \Exception("خطا در دریافت توکن دسترسی");
            }

            $responseBody = $response->getBody()->getContents();
            $tokenData = json_decode($responseBody, true);

            return $tokenData['access_token'] ?? null;

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                $errorResponse = $e->getResponse()->getBody()->getContents();
                throw new \Exception("خطا در دریافت توکن: " . $errorResponse);
            }
            throw new \Exception("خطا در ارتباط با سرور OAuth: " . $e->getMessage());
        }
    }


    public static function cancel($transaction_id)
    {
        // TODO این بخش هنوز تست نشده
        $self = new Snapppay($transaction_id);
        $transaction = LarapayTransaction::find($transaction_id);
        $paymentToken = json_decode($transaction->additional_data, true)['payment_token'];
        $token = $self->getOAuthToken();

        $json = [
            'paymentToken' => $paymentToken
        ];
        $response = $self->client->post(
            $self->getEndPoint() . '/payment/v1/cancel',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'paymentToken' => json_decode($transaction->additional_data, true)['payment_token']
                ]),
            ]
        );

        if ($response->getStatusCode() != 200) {
            throw new \Exception("خطا در دریافت تایید تراکنش");
        }

        $responseBody = $response->getBody()->getContents();
        $tokenData = json_decode($responseBody, true);

        \Log::warning("canceled", compact('responseBody', 'json'));

        if (!$tokenData['successful']) {
            throw new \Exception("تراکنش ناموفق");
        }

        \Log::warning("canceled ....");
    }

    private function getSaleData()
    {
        $transaction = $this->transaction;
        $total = $transaction->model->getAmount();

        $discountAmount = 0;

        return [
            'amount' => $total,
            'cartList' => [
                [
                    'cartId' => 1,
                    'cartItems' => collect($transaction->model->items)->map(function ($item) {

                        return [
                            'amount' => ($item['food']['off_price'] ?: $item['food']['price']) * 10,
                            'category' => $item['food']['name'],
                            'count' => $item['count'],
                            'id' => $item['food']['id'],
                            'name' => $item['food']['name'],
                            'commissionType' => 100
                        ];
                    })->values()->toArray(),
                    'isShipmentIncluded' => true,
                    'isTaxIncluded' => true,
                    'shippingAmount' => 0,
                    'taxAmount' => 0,
                    'totalAmount' => $total + $discountAmount,
                ]
            ],
            'discountAmount' => $discountAmount,
            'externalSourceAmount' => 0,
            'mobile' => '+98' . ((int)$transaction->model->user->mobile),
            'paymentMethodTypeDto' => 'INSTALLMENT',
            'returnURL' => $this->redirect_url,
            'transactionId' => $this->transaction->id,
        ];
    }


    public function update($transactionId)
    {
        // TODO این بخش هنوز تست نشده
        $this->transactionId = $transactionId;
        $token = $this->getOAuthToken();
        $paymentData = $this->getSaleData();
        $paymentToken = json_decode($this->transaction->additional_data, true)['payment_token'];
        $this->updateSnappPayPayment($token, $paymentData, $paymentToken);
    }


    public function eligible($amount)
    {
        // TODO این بخش هنوز تست نشده
        $accessToken = $this->getOAuthToken();
        $response = $this->client->get(
            $this->getEndPoint() . '/offer/v1/eligible?' . http_build_query(['amount' => $amount]),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
            ]
        );

        $responseBody = $response->getBody()->getContents();
        $tokenData = json_decode($responseBody, true);


        return $tokenData;
    }

}
