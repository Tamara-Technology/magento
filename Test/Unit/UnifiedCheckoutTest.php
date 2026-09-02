<?php

namespace Tamara\Checkout\Test\Unit;

use PHPUnit\Framework\TestCase;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Tamara\Checkout\Gateway\Config\InstalmentConfig;
use Tamara\Checkout\Gateway\Config\SingleCheckoutConfig;
use Tamara\Checkout\Helper\AbstractData;
use Tamara\Checkout\Model\Adapter\PreCheckoutEligibilityTransport;
use Tamara\Checkout\Model\Helper\PaymentHelper;
use Tamara\Checkout\Plugin\Model\Method\Available;
use Tamara\Model\Money;
use Tamara\Request\Checkout\PreCheckoutEligibilityRequest;
use Tamara\HttpClient\ClientInterface;

class UnifiedCheckoutTest extends TestCase
{
    public function testEligibilityRequestContainsQuoteAndCustomerData()
    {
        $request = new PreCheckoutEligibilityRequest(
            new Money(300.0, 'SAR'),
            '966501234567',
            'customer@example.com'
        );

        $this->assertSame(
            [
                'order' => [
                    'amount' => 300.0,
                    'currency' => 'SAR'
                ],
                'customer' => [
                    'phone_number' => '966501234567',
                    'email' => 'customer@example.com'
                ]
            ],
            $request->toArray()
        );
    }

    public function testSingleCheckoutUsesUnifiedInstallmentBootstrap()
    {
        $this->assertSame(
            InstalmentConfig::PAY_BY_INSTALMENTS,
            BaseConfig::convertPaymentMethodFromMagentoToTamara(
                SingleCheckoutConfig::PAYMENT_TYPE_CODE
            )
        );
        $this->assertTrue(PaymentHelper::isTamaraPayment(SingleCheckoutConfig::PAYMENT_TYPE_CODE));
    }

    public function testOnlySingleCheckoutMethodSurvivesFiltering()
    {
        $plugin = (new \ReflectionClass(Available::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Available::class, 'filterUnsupportedMethods');
        $method->setAccessible(true);

        $singleCheckout = new PaymentMethodStub(SingleCheckoutConfig::PAYMENT_TYPE_CODE);
        $legacyTamara = new PaymentMethodStub(InstalmentConfig::PAYMENT_TYPE_CODE);
        $otherGateway = new PaymentMethodStub('checkmo');

        $result = $method->invoke(
            $plugin,
            [SingleCheckoutConfig::PAYMENT_TYPE_CODE => []],
            [$singleCheckout, $legacyTamara, $otherGateway]
        );

        $this->assertSame([$singleCheckout, $otherGateway], array_values($result));
    }

    public function testExplicitIneligibleResponseIsCached()
    {
        $cache = new CacheStub();
        $helper = new EligibilityHelperStub(
            $cache,
            new EligibilityClientStub(new EligibilityResponseStub(false))
        );

        $this->assertSame([], $helper->getPaymentTypesForQuote(new QuoteStub()));
        $this->assertSame('0', $cache->savedValue);
        $this->assertSame(AbstractData::PRE_CHECKOUT_ELIGIBILITY_CACHE_LIFE_TIME, $cache->savedLifetime);
    }

    public function testEligibilityFailureFailsOpenWithoutCaching()
    {
        $cache = new CacheStub();
        $helper = new EligibilityHelperStub($cache, new EligibilityClientStub(null, true));

        $this->assertArrayHasKey(
            SingleCheckoutConfig::PAYMENT_TYPE_CODE,
            $helper->getPaymentTypesForQuote(new QuoteStub())
        );
        $this->assertNull($cache->savedValue);
    }

    public function testMalformedEligibilityResponseFailsOpenWithoutCaching()
    {
        $cache = new CacheStub();
        $helper = new EligibilityHelperStub(
            $cache,
            new EligibilityClientStub(new EligibilityResponseStub(true, []))
        );

        $this->assertArrayHasKey(
            SingleCheckoutConfig::PAYMENT_TYPE_CODE,
            $helper->getPaymentTypesForQuote(new QuoteStub())
        );
        $this->assertNull($cache->savedValue);
    }

    public function testCachedIneligibleResultSkipsApiCall()
    {
        $cache = new CacheStub('0');
        $client = new EligibilityClientStub(new EligibilityResponseStub(true));
        $helper = new EligibilityHelperStub($cache, $client);

        $this->assertSame([], $helper->getPaymentTypesForQuote(new QuoteStub()));
        $this->assertSame(0, $client->callCount);
    }

    public function testDebugModeIgnoresEligibilityCache()
    {
        $cache = new CacheStub('0');
        $client = new EligibilityClientStub(new EligibilityResponseStub(true));
        $helper = new EligibilityHelperStub($cache, $client, true, 'en_US', 'SA', true);

        $this->assertArrayHasKey(
            SingleCheckoutConfig::PAYMENT_TYPE_CODE,
            $helper->getPaymentTypesForQuote(new QuoteStub())
        );
        $this->assertSame(1, $client->callCount);
        $this->assertNull($cache->savedValue);
    }

    public function testDisabledTamaraPaymentSkipsEligibilityRequest()
    {
        $cache = new CacheStub();
        $client = new EligibilityClientStub(new EligibilityResponseStub(true));
        $helper = new EligibilityHelperStub($cache, $client, false);

        $this->assertSame([], $helper->getPaymentTypesForQuote(new QuoteStub()));
        $this->assertSame(0, $client->callCount);
        $this->assertNull($cache->savedValue);
    }

    public function testKsaCheckoutShowsShariaCompliantDescription()
    {
        $english = new EligibilityHelperStub(new CacheStub('1'), new EligibilityClientStub(null));
        $arabic = new EligibilityHelperStub(new CacheStub('1'), new EligibilityClientStub(null), true, 'ar_SA');

        $this->assertSame(
            ['title' => 'Tamara', 'description' => 'Monthly Payments. Sharia Compliant.'],
            $english->getPaymentTypesForQuote(new QuoteStub())[SingleCheckoutConfig::PAYMENT_TYPE_CODE]
        );
        $this->assertSame(
            ['title' => 'تمارا', 'description' => 'دفعات شهرية. متوافقة مع الشريعة'],
            $arabic->getPaymentTypesForQuote(new QuoteStub())[SingleCheckoutConfig::PAYMENT_TYPE_CODE]
        );
    }

    public function testNonKsaCheckoutShowsPlainMonthlyPaymentsDescription()
    {
        $english = new EligibilityHelperStub(new CacheStub('1'), new EligibilityClientStub(null));
        $arabic = new EligibilityHelperStub(new CacheStub('1'), new EligibilityClientStub(null), true, 'ar_SA');

        $this->assertSame('Monthly Payments.', $english->getSingleCheckoutDescription('AE'));
        $this->assertSame('دفعات شهرية', $arabic->getSingleCheckoutDescription('AE'));
    }

    public function testCheckoutCountryFallsBackToStoreConfiguration()
    {
        $helper = new EligibilityHelperStub(new CacheStub('1'), new EligibilityClientStub(null), true, 'en_US', '');

        $this->assertSame('AE', $helper->getCheckoutCountryCode(new QuoteStub()));
        $this->assertSame(
            'Monthly Payments.',
            $helper->getPaymentTypesForQuote(new QuoteStub())[SingleCheckoutConfig::PAYMENT_TYPE_CODE]['description']
        );
    }

    public function testUnsupportedStoreCountryFallsBackToCurrencyCountry()
    {
        $helper = new UnsupportedStoreCountryHelperStub(new CacheStub('1'), new EligibilityClientStub(null));

        $this->assertSame('SA', $helper->getCheckoutCountryCode(null, 1, 'SA'));
        $this->assertSame(
            'Monthly Payments. Sharia Compliant.',
            $helper->getPaymentTypesForQuote(new QuoteStub())[SingleCheckoutConfig::PAYMENT_TYPE_CODE]['description']
        );
    }

    public function testAlreadyRegisteredWebhookErrorExposesExistingWebhookId()
    {
        $webhookId = 'f90f644f-b27d-4822-a87e-5d1a4d47baa2';
        $response = new RegisterWebhookResponseStub(
            409,
            'webhook already registered',
            [
                'message' => 'webhook already registered',
                'errors' => [
                    ['error_code' => 'webhook_already_registered', 'webhook_id' => $webhookId]
                ]
            ]
        );

        $this->assertSame($webhookId, $this->invokeAlreadyRegisteredWebhookId($response));
    }

    public function testUnrelatedWebhookErrorKeepsFailing()
    {
        $response = new RegisterWebhookResponseStub(
            400,
            'invalid url',
            ['message' => 'invalid url', 'errors' => [['error_code' => 'invalid_url']]]
        );

        $this->assertNull($this->invokeAlreadyRegisteredWebhookId($response));
    }

    private function invokeAlreadyRegisteredWebhookId($response)
    {
        $adapterClass = \Tamara\Checkout\Model\Adapter\TamaraAdapter::class;
        $adapter = (new \ReflectionClass($adapterClass))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($adapterClass, 'getAlreadyRegisteredWebhookId');
        $method->setAccessible(true);

        return $method->invoke($adapter, $response);
    }

    public function testEligibilityTransportUsesSdkContractAndTimeout()
    {
        $transport = new PreCheckoutEligibilityTransport(\Tamara\Checkout\Model\Adapter\TamaraAdapter::PRE_CHECKOUT_ELIGIBILITY_TIMEOUT);
        $timeout = new \ReflectionProperty(PreCheckoutEligibilityTransport::class, 'timeout');
        $timeout->setAccessible(true);

        $this->assertInstanceOf(ClientInterface::class, $transport);
        $this->assertSame(2, $timeout->getValue($transport));
    }
}

class PaymentMethodStub
{
    private $code;

    public function __construct($code)
    {
        $this->code = $code;
    }

    public function getCode()
    {
        return $this->code;
    }
}

class EligibilityHelperStub extends AbstractData
{
    private $countryId;

    public function __construct($cache, $client, $paymentEnabled = true, $locale = 'en_US', $countryId = 'SA', $debugEnabled = false)
    {
        $this->magentoCache = $cache;
        $this->tamaraAdapterFactory = new AdapterFactoryStub($client);
        $this->tamaraConfig = new TamaraConfigStub($paymentEnabled, $debugEnabled);
        $this->locale = new LocaleStub($locale);
        $this->countryId = $countryId;
    }

    public function getShippingAddressFromQuote($quote)
    {
        return new AddressStub($this->countryId);
    }

    public function getStoreCountryCode($storeId = null)
    {
        return 'AE';
    }

    public function isAllowedCurrency($currency, $storeId)
    {
        return true;
    }

    public function getLogger()
    {
        return new LoggerStub();
    }
}

class UnsupportedStoreCountryHelperStub extends EligibilityHelperStub
{
    public function __construct($cache, $client)
    {
        parent::__construct($cache, $client, true, 'en_US', '');
    }

    public function getStoreCountryCode($storeId = null)
    {
        return 'US';
    }
}

class TamaraConfigStub
{
    private $paymentEnabled;
    private $debugEnabled;

    public function __construct($paymentEnabled, $debugEnabled = false)
    {
        $this->paymentEnabled = $paymentEnabled;
        $this->debugEnabled = $debugEnabled;
    }

    public function isEnableTamaraPayment($storeId = null)
    {
        return $this->paymentEnabled;
    }

    public function enabledDebug($storeId = null)
    {
        return $this->debugEnabled;
    }
}

class QuoteStub
{
    public function getStoreId()
    {
        return 1;
    }

    public function getCurrency()
    {
        return new CurrencyStub();
    }

    public function getGrandTotal()
    {
        return 300.0;
    }

    public function getCustomerEmail()
    {
        return 'customer@example.com';
    }

    public function getCustomer()
    {
        return null;
    }
}

class CurrencyStub
{
    public function getQuoteCurrencyCode()
    {
        return 'SAR';
    }
}

class LocaleStub
{
    private $locale;

    public function __construct($locale)
    {
        $this->locale = $locale;
    }

    public function getLocale()
    {
        return $this->locale;
    }
}

class AddressStub
{
    private $countryId;

    public function __construct($countryId = 'SA')
    {
        $this->countryId = $countryId;
    }

    public function getCountryId()
    {
        return $this->countryId;
    }

    public function getTelephone()
    {
        return '966501234567';
    }

    public function getEmail()
    {
        return 'customer@example.com';
    }
}

class CacheStub
{
    public $savedValue;
    public $savedLifetime;
    private $loadedValue;

    public function __construct($loadedValue = false)
    {
        $this->loadedValue = $loadedValue;
    }

    public function load($identifier)
    {
        return $this->loadedValue;
    }

    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $this->savedValue = $data;
        $this->savedLifetime = $lifeTime;
        return true;
    }
}

class AdapterFactoryStub
{
    private $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    public function create($storeId)
    {
        return new AdapterStub($this->client);
    }
}

class AdapterStub
{
    private $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    public function getPreCheckoutEligibilityClient()
    {
        return $this->client;
    }
}

class EligibilityClientStub
{
    public $callCount = 0;
    private $response;
    private $shouldThrow;

    public function __construct($response, $shouldThrow = false)
    {
        $this->response = $response;
        $this->shouldThrow = $shouldThrow;
    }

    public function preCheckoutEligibility($request)
    {
        $this->callCount++;
        if ($this->shouldThrow) {
            throw new \RuntimeException('timeout');
        }

        return $this->response;
    }
}

class EligibilityResponseStub
{
    private $eligible;
    private $content;

    public function __construct($eligible, $content = null)
    {
        $this->eligible = $eligible;
        $this->content = $content;
    }

    public function isSuccess()
    {
        return true;
    }

    public function getContent()
    {
        return json_encode(
            $this->content === null ? ['is_eligible' => $this->eligible] : $this->content
        );
    }

    public function isEligible()
    {
        return $this->eligible;
    }
}

class RegisterWebhookResponseStub
{
    private $statusCode;
    private $message;
    private $content;

    public function __construct($statusCode, $message, array $content)
    {
        $this->statusCode = $statusCode;
        $this->message = $message;
        $this->content = $content;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function getErrors()
    {
        return $this->content['errors'] ?? [];
    }

    public function getContent()
    {
        return json_encode($this->content);
    }
}

class LoggerStub
{
    public function debug($message)
    {
    }
}
