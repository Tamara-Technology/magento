<?php

namespace Tamara\Checkout\Controller;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Framework\Exception\NotFoundException;

class Router implements RouterInterface
{
    private const
        MODULE_NAME = 'tamara',
        CONTROLLER_NAME = 'payment';

    private $actions = ['success', 'cancel', 'failure'];

    /**
     * @var \Magento\Framework\App\ActionFactory
     */
    protected $actionFactory;

    /**
     * Event manager
     *
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $_eventManager;

    /**
     * Store manager
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;


    /**
     * Config primary
     *
     * @var \Magento\Framework\App\State
     */
    protected $_appState;

    /**
     * Url
     *
     * @var \Magento\Framework\UrlInterface
     */
    protected $_url;

    /**
     * Response
     *
     * @var \Magento\Framework\App\ResponseInterface
     */
    protected $_response;

    public function __construct(
        \Magento\Framework\App\ActionFactory $actionFactory,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\UrlInterface $url,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\ResponseInterface $response
    ) {
        $this->actionFactory = $actionFactory;
        $this->_eventManager = $eventManager;
        $this->_url = $url;
        $this->_storeManager = $storeManager;
        $this->_response = $response;
    }

    public function match(RequestInterface $request)
    {
        $identifier = trim($request->getPathInfo(), '/');
        $urlParts = explode('/', $identifier);

        $offset = $this->resolveOffset($urlParts);
        if ($offset === null) {
            return;
        }

        $request->setModuleName(self::MODULE_NAME)
            ->setControllerName(self::CONTROLLER_NAME)
            ->setActionName($urlParts[$offset + 3])
            ->setParam('order_id', $urlParts[$offset + 2]);

        $request->setAlias(\Magento\Framework\Url::REWRITE_REQUEST_PATH_ALIAS, $identifier);
        return $this->actionFactory->create(\Magento\Framework\App\Action\Forward::class);
    }

    private function resolveOffset(array $urlParts): ?int
    {
        foreach ([0, 1] as $offset) {
            if ($this->isCorrectUrl($urlParts, $offset)) {
                return $offset;
            }
        }

        return null;
    }

    private function isCorrectUrl(array $urlParts, int $offset): bool
    {
        $parts = array_slice($urlParts, $offset, 4);
        if (count($parts) < 4 || in_array('', $parts, true)) {
            return false;
        }

        return $parts[0] === self::MODULE_NAME
            && $parts[1] === self::CONTROLLER_NAME
            && $parts[2] !== '0'
            && is_numeric($parts[2])
            && in_array($parts[3], $this->actions, true);
    }

}