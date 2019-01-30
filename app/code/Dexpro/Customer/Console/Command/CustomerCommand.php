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
                                \Dexpro\Customer\Logger\Logger $logger,
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
        $state = $this->objectManager->get('Magento\Framework\App\State');
        $state->setAreaCode('adminhtml');
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


            $periodHour = $this->_scopeConfig->getValue("dexpro_configuration/general/erp_period", $storeScope);
            $periodHour = (int)$periodHour;
            $vars = explode(':',date('H:i'));
            $period = (int)$vars[0];
            if ($period % $periodHour == 0) {
                $this->uploadCustomerInfo($authCode);
                $this->uploadSalesOrderInfo($authCode);
            }

        }
    }

    public function uploadSalesOrderInfo($authCode) {

        //Get All Orders
        $orderDataModel = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection();
        foreach($orderDataModel as $eachOrder){
            $orderInfo = $eachOrder->getData();

            //Create Sales Order if not exist in API
            if ($this->isExistOrderInAPI($authCode, $eachOrder['entity_id'])) {
                continue;
            }

            $url = $this->erp_url.'/api/warehouse/salesorder';

            $order_fields = array(
                'code' => $orderInfo['entity_id'],
                'warehouse' => '1',
                'division' => '1',
                'customer' => $this->getCustomerIdByCode($authCode, $orderInfo['customer_id']),
                'currency' => '1',
                'currencyRate' => '1',
                'orderDate' => $this->getValidDate($orderInfo['created_at']),
                'requiredDate' => $this->getValidDate($orderInfo['updated_at']),
                'discountAmount' => $orderInfo['discount_amount'],
                'discountPercent' => '0',
                'invoiceMethod' => 'SO',
                'email' => $orderInfo['customer_email']
            );
            $fields = array('salesOrder' => $order_fields);
            $postFields = http_build_query($fields);
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, $authCode);
            curl_setopt($curl,CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            $result = curl_exec($curl);
            curl_close($curl);
            $result = json_decode($result);
            $orderId = $result->id;

            //Create Sales Order Line for Order
            $orderData = $this->objectManager->create('Magento\Sales\Model\Order')->loadByIncrementId($orderInfo['increment_id']);
            $orderItems = $orderData->getAllVisibleItems();
            foreach($orderItems as $orderItem){
                $url = $this->erp_url.'/api/warehouse/salesorderline';
                $orderLine_fields = array(
                    'product' => $this->getProductIdByCode($authCode, $orderItem['sku']),
                    'salesOrder' => $orderId,
                    'quantity' => intval($orderItem['qty_ordered']),
                    'salesPriceUnitOfMeasurePriceIncludingTax' => floatval($orderItem['price']),
                    'isIncludedInSalesOrderTotals' => '0',
                    'isPrintedOnSalesInvoice' => '0',
                    'serviceAndHandlingFee' => '0'
                );

                $fields = array('salesOrderLine' => $orderLine_fields);
                $postFields = http_build_query($fields);
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($curl, CURLOPT_USERPWD, $authCode);
                curl_setopt($curl,CURLOPT_POSTFIELDS, $postFields);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                $result = curl_exec($curl);
                curl_close($curl);
            }
        }
        $this->_logger->info('Sales Data Update is finished.');
    }

    public function uploadCustomerInfo($authCode) {

        $customerObj = $this->objectManager->create('Magento\Customer\Model\Customer')->getCollection();

        foreach($customerObj as $customer)
        {
            //Checks if customer is exist in warehouse
            if ($this->getCustomerIdByCode($authCode, $customer->getId()) > 0) {
                continue;
            }

            //Create Customer Address
            $billingAddress = $customer->getDefaultBillingAddress();

            $url = $this->erp_url.'/api/warehouse/customeraddress';
            $address_fields = array(
                'code' => 'address-'.$customer->getId(),
                'address1' => isset($billingAddress->getStreet()[0]) ? $billingAddress->getStreet()[0] : '',
                'address2' => isset($billingAddress->getStreet()[1]) ? $billingAddress->getStreet()[1] : '',
                'suburb' => $billingAddress->getCity(),
                'state' => $billingAddress->getRegion(),
                'postCode' => $billingAddress->getPostcode(),
                'country' => $billingAddress->getCountryId()
            );
            $fields = array('customerAddress' => $address_fields);
            $postFields = http_build_query($fields);
            $curl = curl_init();

            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, $authCode);
            curl_setopt($curl,CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

            $result = curl_exec($curl);

            curl_close($curl);
            $result = json_decode($result);

            $addressId = $result->id;

            //Create Customer in Warehouse
            $url = $this->erp_url.'/api/warehouse/customer';
            $companyName = $customer->getFirstname().' '.$customer->getLastname();
            $customer_fields = array(
                'code' => $customer->getId(),
                'invoiceMethod' => 'SO',
                'currency' => '1',
                'paymentCurrency' => '1',
                'companyName' => $companyName,
                "phoneNumber" => $billingAddress->getTelephone(),
                'email' => $customer->getEmail(),
                'customerSegment' => '1',
                'paymentMethod' => '2',
                'defaultInvoiceAddress' => $addressId,
                'defaultDeliveryAddress' => $addressId,
                'salesPricingGroup' => '1'
            );

            $fields = array('customer' => $customer_fields);

            $postFields = http_build_query($fields);

            $curl = curl_init();

            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, $authCode);
            curl_setopt($curl,CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

            $result = curl_exec($curl);
            curl_close($curl);
        }
        $this->_logger->info('Customer Update is finished.');
    }

    public function getCustomerIdByCode($authCode, $code) {
        $url = $this->erp_url.'/api/warehouse/customer';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $authCode);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl);
        $result = json_decode($result);
        curl_close($curl);

        $array = json_decode(json_encode($result), true);


        foreach ($array as $each) {
            if ($each['code'] === $code) {
                return intval($each['id']);
            }
        }
        return 0;
    }

    public function getProductIdByCode($authCode, $code) {
        $url = $this->erp_url.'/api/warehouse/product/findbycode?code='.rawurlencode($code);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $authCode);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl);

        $result = json_decode($result);
        curl_close($curl);

        $array = json_decode(json_encode($result), true);
        if (isset($array['id'])) {
            return $array['id'];
        }
        return 0;

    }

    public function isExistOrderInAPI($authCode, $code) {
        $url = $this->erp_url.'/api/warehouse/salesorder';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $authCode);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl);
        $result = json_decode($result);
        curl_close($curl);

        $array = json_decode(json_encode($result), true);

        foreach ($array as $each) {
            if ($each['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    public function getValidDate($date) {
        return explode(' ',$date)[0];
    }
}