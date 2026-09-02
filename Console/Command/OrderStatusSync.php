<?php

namespace Tamara\Checkout\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tamara\Checkout\Gateway\Config\BaseConfig;

class OrderStatusSync extends Command
{
    const START_TIME = 'start-time';
    const END_TIME = 'end-time';
    const STORE_ID = 'store-id';

    /**
     * @var \Tamara\Checkout\Helper\AbstractData
     */
    protected $helper;

    /**
     * @var BaseConfig
     */
    protected $config;

    /**
     * @var \Magento\Framework\App\State
     */
    private $state;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var \Tamara\Checkout\Cron\OrderStatusSync
     */
    private $orderStatusSync;

    /**
     * @param \Magento\Framework\App\State $state
     * @param \Tamara\Checkout\Helper\AbstractData $helper
     * @param \Tamara\Checkout\Cron\OrderStatusSync $orderStatusSync
     * @param BaseConfig $config
     * @param string|null $name
     */
    public function __construct(
        \Magento\Framework\App\State $state,
        \Tamara\Checkout\Helper\AbstractData $helper,
        \Tamara\Checkout\Cron\OrderStatusSync $orderStatusSync,
        BaseConfig $config,
        ?string $name = null
    ) {
        $this->state = $state;
        $this->helper = $helper;
        $this->orderStatusSync = $orderStatusSync;
        $this->config = $config;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName("tamara:orders-status-sync");
        $this->setDescription("Sync order status with Tamara API and process orders accordingly");
        $this->addOption(
            self::START_TIME,
            null,
            InputOption::VALUE_OPTIONAL,
            'Start time to process orders from',
            '-40 minutes'
        );
        $this->addOption(
            self::END_TIME,
            null,
            InputOption::VALUE_OPTIONAL,
            'End time to process orders to',
            'now'
        );
        $this->addOption(
            self::STORE_ID,
            null,
            InputOption::VALUE_OPTIONAL,
            'Store ID',
            '0'
        );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepare($input, $output);
        $this->process();
        return 0;
    }

    protected function prepare(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $exception) {
            //nothing
        }

        $this->input = $input;
        $this->output = $output;
        $this->helper->setOutput($output);
    }

    protected function process()
    {
        $this->helper->log(["Run order status sync from console"]);
        
        try {
            $startTime = $this->input->getOption(self::START_TIME);
            $storeId = $this->input->getOption(self::STORE_ID);
            
            // Call the cron job implementation with custom parameters
            $this->orderStatusSync->syncOrderStatus($startTime, $storeId);
            
        } catch (\Exception $exception) {
            // just log the error and don't break the job
            $this->helper->log(["Error when process syncOrderStatus" => $exception->getMessage()], true);
        }
        
        $this->helper->log(["Order status sync completed"]);
    }
}
