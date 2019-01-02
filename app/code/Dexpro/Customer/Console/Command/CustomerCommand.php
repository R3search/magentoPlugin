<?php

namespace Dexpro\Customer\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;

class CustomerCommand extends \Symfony\Component\Console\Command\Command
{
    protected $objectManager;
    protected $directory_list;
    protected $_scopeConfig;
    protected $_logger;
    public $erp_url;

    public function __construct(\Magento\Framework\ObjectManagerInterface $objectmanager,
                                \Magento\Framework\App\Filesystem\DirectoryList $directory_list,
                                \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
                                \Dexpro\Catalog\Logger\Logger $logger,
                                $name = null
    )
    {
        $this->objectManager = $objectmanager;
        $this->directory_list = $directory_list;
        $this->_scopeConfig = $scopeConfig;
        $this->_logger = $logger;
        return parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('dexpro:customer');
        $this->setDescription('Upload Customer to ERP');
        parent::configure();
    }

    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    )
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORES;
        $moduleEnabled = $this->_scopeConfig->getValue("dexpro_configuration/general/enable", $storeScope);
        if ($moduleEnabled === '1') {
            $userName = $this->_scopeConfig->getValue("dexpro_configuration/general/erp_username", $storeScope);
            $password = $this->_scopeConfig->getValue("dexpro_configuration/general/erp_password", $storeScope);
            $this->erp_url = $this->_scopeConfig->getValue("dexpro_configuration/general/erp_url", $storeScope);
            if (substr($this->erp_url, -1) === '/') {
                $this->erp_url = substr($this->erp_url,0,strlen($this->erp_url) - 1);
            }
            $authCode = $userName . ":" . $password;

            $url = $this->erp_url.'/api/warehouse/customer';
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, $authCode);

            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            $result = curl_exec($curl);
            $result = json_decode($result);
            if ($result === NULL) {
                $this->_logger->info('ERP URL is wrong. Please check account information.');
                return;
            }

            if (array_key_exists('error', $result)) {
                $this->_logger->info('Authorization is wrong.');
                return;
            }

            $this->uploadCustomerInfo($authCode);

            /*$periodHour = $this->_scopeConfig->getValue("dexpro_configuration/general/erp_period", $storeScope);
            $periodHour = (int)$periodHour;
            $vars = explode(':',date('H:i'));
            $period = (int)$vars[0];
            if ($period % $periodHour == 0) {
                $this->updateCategoryInfo($authCode);
                $this->updateProductInfo($authCode);
                $this->_logger->info('Catalog Update is finished.');
            }*/

        }
    }

    public function uploadCustomerInfo($authCode) {
        $state = $this->objectManager->get('Magento\Framework\App\State');
        $state->setAreaCode('adminhtml');
        //Get Json String from API
        $url = $this->erp_url.'/api/warehouse/customer';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $authCode);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl);
        $result = json_decode($result);

        curl_close($curl);

        var_dump($result);
    }
}