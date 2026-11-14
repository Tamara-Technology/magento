<?php

namespace Tamara\Checkout\Test\Unit\Controller;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tamara\Checkout\Controller\Router;

class RouterTest extends TestCase
{
    private $actionFactory;
    private $router;

    protected function setUp(): void
    {
        $this->actionFactory = $this->createMock(ActionFactory::class);
        $this->router = new Router(
            $this->actionFactory,
            $this->createStub(ManagerInterface::class),
            $this->createStub(UrlInterface::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(ResponseInterface::class)
        );
    }

    /**
     * @dataProvider matchingPathProvider
     */
    #[DataProvider('matchingPathProvider')]
    public function testMatchesPaymentUrl(string $pathInfo, string $orderId, string $action): void
    {
        $forward = $this->createStub(Forward::class);
        $this->actionFactory->expects($this->once())
            ->method('create')
            ->with(Forward::class)
            ->willReturn($forward);

        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn($pathInfo);
        $request->expects($this->once())->method('setModuleName')->with('tamara')->willReturnSelf();
        $request->expects($this->once())->method('setControllerName')->with('payment')->willReturnSelf();
        $request->expects($this->once())->method('setActionName')->with($action)->willReturnSelf();
        $request->expects($this->once())->method('setParam')->with('order_id', $orderId)->willReturnSelf();

        $this->assertSame($forward, $this->router->match($request));
    }

    public static function matchingPathProvider(): array
    {
        return [
            'no store code' => ['/tamara/payment/189720/success', '189720', 'success'],
            'store code prefix' => ['/sa-ar/tamara/payment/189720/success', '189720', 'success'],
            'store code prefix, failure' => ['/sa-en/tamara/payment/189542/failure', '189542', 'failure'],
            'store code prefix, cancel' => ['/ae-en/tamara/payment/5/cancel', '5', 'cancel'],
        ];
    }

    /**
     * @dataProvider nonMatchingPathProvider
     */
    #[DataProvider('nonMatchingPathProvider')]
    public function testIgnoresOtherUrls(string $pathInfo): void
    {
        $this->actionFactory->expects($this->never())->method('create');

        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn($pathInfo);
        $request->expects($this->never())->method('setModuleName');

        $this->assertNull($this->router->match($request));
    }

    public static function nonMatchingPathProvider(): array
    {
        return [
            'unknown action' => ['/tamara/payment/189720/unknown'],
            'non numeric order id' => ['/tamara/payment/abc/success'],
            'store code prefix, unknown action' => ['/sa-ar/tamara/payment/189720/unknown'],
            'store code prefix, non numeric order id' => ['/sa-ar/tamara/payment/abc/success'],
            'other module' => ['/sa-ar/checkout/payment/189720/success'],
            'too short' => ['/tamara/payment/189720'],
            'order id zero' => ['/tamara/payment/0/success'],
            'store code prefix, order id zero' => ['/sa-ar/tamara/payment/0/success'],
            'two prefix segments' => ['/a/b/tamara/payment/1/success'],
            'empty' => ['/'],
        ];
    }
}
