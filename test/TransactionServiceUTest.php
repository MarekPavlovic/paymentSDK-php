<?php
/**
 * Shop System Plugins - Terms of Use
 *
 * The plugins offered are provided free of charge by Wirecard Central Eastern Europe GmbH
 * (abbreviated to Wirecard CEE) and are explicitly not part of the Wirecard CEE range of
 * products and services.
 *
 * They have been tested and approved for full functionality in the standard configuration
 * (status on delivery) of the corresponding shop system. They are under General Public
 * License Version 3 (GPLv3) and can be used, developed and passed on to third parties under
 * the same terms.
 *
 * However, Wirecard CEE does not provide any guarantee or accept any liability for any errors
 * occurring when used in an enhanced, customized shop system configuration.
 *
 * Operation in an enhanced, customized configuration is at your own risk and requires a
 * comprehensive test phase by the user of the plugin.
 *
 * Customers use the plugins at their own risk. Wirecard CEE does not guarantee their full
 * functionality neither does Wirecard CEE assume liability for any disadvantages related to
 * the use of the plugins. Additionally, Wirecard CEE does not guarantee the full functionality
 * for customized shop systems or installed plugins of other vendors of plugins within the same
 * shop system.
 *
 * Customers are responsible for testing the plugin's functionality before starting productive
 * operation.
 *
 * By installing the plugin into the shop system the customer agrees to these terms of use.
 * Please do not use the plugin if you do not agree to these terms of use!
 */

namespace WirecardTest\PaymentSdk;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Wirecard\PaymentSdk\Config;
use Wirecard\PaymentSdk\Entity\Money;
use Wirecard\PaymentSdk\Transaction\CancelTransaction;
use Wirecard\PaymentSdk\Response\InteractionResponse;
use Wirecard\PaymentSdk\Exception\MalformedResponseException;
use Wirecard\PaymentSdk\Transaction\PayTransaction;
use Wirecard\PaymentSdk\Transaction\ThreeDAuthorizationTransaction;
use Wirecard\PaymentSdk\Entity\StatusCollection;
use Wirecard\PaymentSdk\Response\SuccessResponse;
use Wirecard\PaymentSdk\TransactionService;

class TransactionServiceUTest extends \PHPUnit_Framework_TestCase
{
    const HANDLER = 'handler';
    /**
     * @var TransactionService
     */
    private $instance;

    /**
     * @var Config
     */
    private $config;

    public function setUp()
    {
        $this->config = $this->createMock('\Wirecard\PaymentSdk\Config');
        $this->config->method('getHttpUser')->willReturn('abc123');
        $this->config->method('getHttpPassword')->willReturn('password');
        $this->config->method('getMerchantAccountId')->willReturn('maid');
        $this->config->method('getUrl')->willReturn('http://engine.ok');
        $this->config->method('getDefaultCurrency')->willReturn('EUR');

        $this->instance = new TransactionService($this->config);
    }

    public function testFullConstructor()
    {
        $logger = $this->createMock('\Monolog\Logger');
        $httpClient = $this->createMock('\GuzzleHttp\Client');
        $requestMapper = $this->createMock('\Wirecard\PaymentSdk\Mapper\RequestMapper');
        $responseMapper = $this->createMock('\Wirecard\PaymentSdk\Mapper\ResponseMapper');
        $requestIdGenerator = function () {
            return 42;
        };

        $service = new TransactionService(
            $this->config,
            $logger,
            $httpClient,
            $requestMapper,
            $responseMapper,
            $requestIdGenerator
        );

        $this->assertAttributeEquals($this->config, 'config', $service);
        $this->assertAttributeEquals($logger, 'logger', $service);
        $this->assertAttributeEquals($requestMapper, 'requestMapper', $service);
        $this->assertAttributeEquals($responseMapper, 'responseMapper', $service);
        $this->assertAttributeEquals($requestIdGenerator, 'requestIdGenerator', $service);
    }

    public function testGetConfig()
    {
        $helper = function () {
            return $this->getConfig();
        };

        $method = $helper->bindTo($this->instance, $this->instance);

        $this->assertEquals($this->config, $method());
    }

    public function testGetLogger()
    {
        $helper = function () {
            return $this->getLogger();
        };

        $method = $helper->bindTo($this->instance, $this->instance);

        $this->assertInstanceOf('\Psr\Log\LoggerInterface', $method());
    }

    public function testGetHttpClient()
    {
        $helper = function () {
            return $this->getHttpClient();
        };

        $method = $helper->bindTo($this->instance, $this->instance);

        $this->assertInstanceOf('\GuzzleHttp\Client', $method());
    }

    public function testGetRequestMapper()
    {
        $helper = function () {
            return $this->getRequestMapper();
        };

        $method = $helper->bindTo($this->instance, $this->instance);

        $this->assertInstanceOf('\Wirecard\PaymentSdk\Mapper\RequestMapper', $method());
    }

    public function testGetResponseMapper()
    {
        $helper = function () {
            return $this->getResponseMapper();
        };

        $method = $helper->bindTo($this->instance, $this->instance);

        $this->assertInstanceOf('\Wirecard\PaymentSdk\Mapper\ResponseMapper', $method());
    }

    public function testGetRequestIdGenerator()
    {
        $helper = function () {
            return $this->getRequestIdGenerator();
        };

        $method = $helper->bindTo($this->instance, $this->instance);

        $this->assertInstanceOf('Closure', $method());
    }

    /**
     * @param $response
     * @param $class
     * @dataProvider testPayProvider
     */
    public function testPay($response, $class)
    {
        $mock = new MockHandler([
            $response
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client([self::HANDLER => $handler, 'http_errors' => false]);

        $service = new TransactionService($this->config, null, $client);

        $this->assertInstanceOf($class, $service->process($this->getTransactionMock()));
    }

    public function testReserveCreditCardTransaction()
    {
        $transaction = $this->createMock('\Wirecard\PaymentSdk\Transaction\ReserveTransaction');

        //prepare RequestMapper
        $mappedRequest = '{"mocked": "json", "response": "object"}';
        $requestMapper = $this->createMock('\Wirecard\PaymentSdk\Mapper\RequestMapper');
        $requestMapper->expects($this->once())
            ->method('map')
            ->with($this->equalTo($transaction))
            ->willReturn($mappedRequest);

        //prepare Guzzle
        $responseToMap = '<payment><xml-response></xml-response></payment>';
        $guzzleMock = new MockHandler([
            new Response(200, [], '<payment><xml-response></xml-response></payment>')
        ]);
        $handler = HandlerStack::create($guzzleMock);
        $client = new Client([self::HANDLER => $handler, 'http_errors' => false]);

        //prepare ResponseMapper
        $responseMapper = $this->createMock('\Wirecard\PaymentSdk\Mapper\ResponseMapper');
        $response = $this->createMock('\Wirecard\PaymentSdk\Response\Response');
        $responseMapper->expects($this->once())
            ->method('map')
            ->with($this->equalTo($responseToMap))
            ->willReturn($response);

        $service = new TransactionService($this->config, null, $client, $requestMapper, $responseMapper);
        $this->assertEquals($response, $service->process($transaction));
    }

    protected function getTransactionMock()
    {
        $paypalData = $this->createMock('\Wirecard\PaymentSdk\Transaction\PayPalData');

        $redirect = $this->createMock('\Wirecard\PaymentSdk\Entity\Redirect');
        $redirect->method('getSuccessUrl')->willReturn('http://www.example.com/success');
        $redirect->method('getCancelUrl')->willReturn('http://www.example.com/cancel');

        $paypalData->method('getRedirect')->willReturn($redirect);

        $tx = new PayTransaction(new Money(20.23, 'EUR'));
        $tx->setPaymentTypeSpecificData($paypalData);

        return $tx;
    }

    public function testPayProvider()
    {
        return [
            [
                new Response(
                    200,
                    [],
                    '<payment>
                        <transaction-state>success</transaction-state>
                        <transaction-id>myid</transaction-id>
                        <statuses>
                            <status code="200" description="test: payment OK" severity="information"/>
                        </statuses>
                        <payment-methods>
                            <payment-method name="paypal" url="http://www.example.com" />
                        </payment-methods>
                    </payment>'
                ),
                '\Wirecard\PaymentSdk\Response\InteractionResponse'
            ],
            [
                new Response(
                    400,
                    [],
                    '<payment>
                        <transaction-state>failure</transaction-state>
                        <statuses>
                            <status code="42" description="my test" severity="information"/>
                        </statuses>
                    </payment>'
                ),
                '\Wirecard\PaymentSdk\Response\FailureResponse'
            ]
        ];
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     */
    public function testPayRequestException()
    {
        $mock = new MockHandler([
            new Response(500, [], json_encode([
                'payment' => [
                    'transaction-state' => 'success',
                ]
            ]))
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client([self::HANDLER => $handler]);

        $this->instance = new TransactionService($this->config, null, $client);

        $this->instance->process($this->getTransactionMock());
    }

    /**
     * @expectedException \Wirecard\PaymentSdk\Exception\MalformedResponseException
     */
    public function testPayMalformedResponseException()
    {
        $mock = new MockHandler([
            new Response(
                200,
                [],
                '<payment><transaction-state>success</transaction-state></payment>'
            )
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client([self::HANDLER => $handler]);

        $this->instance = new TransactionService($this->config, null, $client);

        $this->instance->process($this->getTransactionMock());
    }

    public function testHandleNotificationHappyPath()
    {
        $validXmlContent = '<xml><payment></payment></xml>';

        $responseMapper = $this->createMock('Wirecard\PaymentSdk\Mapper\ResponseMapper');
        $interactionResponse = new InteractionResponse('dummy', new StatusCollection(), 'x', 'y');
        $responseMapper->method('map')->with($validXmlContent)->willReturn($interactionResponse);

        $this->instance = new TransactionService($this->config, null, null, null, $responseMapper);

        $result = $this->instance->handleNotification($validXmlContent);

        $this->assertEquals($interactionResponse, $result);
    }

    /**
     * @expectedException \Wirecard\PaymentSdk\Exception\MalformedResponseException
     */
    public function testHandleNotificationMalformedResponseException()
    {
        $invalidXmlContent = '<xml><payment></payment></xml>';

        $responseMapper = $this->createMock('Wirecard\PaymentSdk\Mapper\ResponseMapper');
        $responseMapper->method('map')->with($invalidXmlContent)->willThrowException(new MalformedResponseException());

        $this->instance = new TransactionService($this->config, null, null, null, $responseMapper);

        $this->instance->handleNotification($invalidXmlContent);
    }

    public function testHandleResponseHappyPath()
    {
        $validContent = [
            'eppresponse' => base64_encode('<xml><payment></payment></xml>')
        ];

        $responseMapper = $this->createMock('Wirecard\PaymentSdk\Mapper\ResponseMapper');
        $interactionResponse = new InteractionResponse('dummy', new StatusCollection(), 'x', 'y');
        $responseMapper->method('map')->with($validContent['eppresponse'])->willReturn($interactionResponse);

        $this->instance = new TransactionService($this->config, null, null, null, $responseMapper);

        $result = $this->instance->handleResponse($validContent);

        $this->assertEquals($interactionResponse, $result);
    }

    /**
     * @expectedException \Wirecard\PaymentSdk\Exception\MalformedResponseException
     */
    public function testHandleResponseMalformedResponseException()
    {
        $invalidXmlContent = [];

        $responseMapper = $this->createMock('Wirecard\PaymentSdk\Mapper\ResponseMapper');

        $this->instance = new TransactionService($this->config, null, null, null, $responseMapper);

        $this->instance->handleResponse($invalidXmlContent);
    }

    public function testGetDataForCreditCardUi()
    {
        $requestIdGenerator = function () {
            return 'abc123';
        };

        $this->instance = new TransactionService($this->config, null, null, null, null, $requestIdGenerator);
        $data = json_decode($this->instance->getDataForCreditCardUi(), true);

        $this->assertArrayHasKey('request_signature', $data);
        unset($data['request_signature']);

        $this->assertArrayHasKey('request_time_stamp', $data);
        unset($data['request_time_stamp']);

        $this->assertEquals(array(
            'request_id'                => 'abc123',
            'merchant_account_id'       => $this->config->getMerchantAccountId(),
            'transaction_type'          => 'tokenize',
            'requested_amount'          => 0,
            'requested_amount_currency' => $this->config->getDefaultCurrency(),
            'payment_method'            => 'creditcard',
        ), $data);
    }

    public function testHandleResponseThreeD()
    {
        $validContent = [
            'MD' => 'arbitrary MD',
            'PaRes' => 'arbitrary PaRes'
        ];
        $refTrans = new ThreeDAuthorizationTransaction($validContent);

        $successResponse = $this->mockProcessingRequest($refTrans);

        $result = $this->instance->handleResponse($validContent);

        $this->assertEquals($successResponse, $result);
    }

    public function testCancel()
    {
        $cancelTrans = new CancelTransaction('parent-id');

        $successResponse = $this->mockProcessingRequest($cancelTrans);

        $result = $this->instance->process($cancelTrans);

        $this->assertEquals($successResponse, $result);
    }

    public function testRequestIdGeneratorRandomness()
    {
        $this->instance = new TransactionService($this->config, null, null, null, null, null);

        $requestId = call_user_func($this->instance->getRequestIdGenerator());
        usleep(1);
        $laterRequestId = call_user_func($this->instance->getRequestIdGenerator());

        $this->assertNotEquals($requestId, $laterRequestId);
    }

    /**
     * @param $tx
     * @return SuccessResponse
     */
    private function mockProcessingRequest($tx)
    {
        $requestMapper = $this->createMock('Wirecard\PaymentSdk\Mapper\RequestMapper');
        $authRequestObject = "dummy_request_payload";
        $requestMapper->method('map')->with($tx)->willReturn($authRequestObject);

        $httpResponse = $this->createMock('\Psr\Http\Message\ResponseInterface');
        $client = $this->createMock('\GuzzleHttp\Client');
        $client->method('request')->willReturn($httpResponse);
        $httpResponseBody = $this->createMock('\Psr\Http\Message\StreamInterface');
        $httpResponse->method('getBody')->willReturn($httpResponseBody);
        $httpResponseContent = 'content';
        $httpResponseBody->method('getContents')->willReturn($httpResponseContent);

        $successResponse = new SuccessResponse('dummy', new StatusCollection(), 'x', 'y');
        $responseMapper = $this->createMock('Wirecard\PaymentSdk\Mapper\ResponseMapper');
        $responseMapper->method('map')->with($httpResponseContent)->willReturn($successResponse);

        $this->instance = new TransactionService($this->config, null, $client, $requestMapper, $responseMapper);
        return $successResponse;
    }
}
