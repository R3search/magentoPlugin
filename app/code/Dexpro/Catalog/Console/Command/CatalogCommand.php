<?php

namespace Dexpro\Catalog\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;

class CatalogCommand extends \Symfony\Component\Console\Command\Command
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
        $this->setName('dexpro:catalog');
        $this->setDescription('Import and Update Catalog from ERP');
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

            //Make sure catalog/category directory exists
            if (!file_exists('pub/media/catalog') && !is_dir('pub/media/catalog')) {
                mkdir('pub/media/catalog', 0755);
            }
            if (!file_exists('pub/media/catalog/category') && !is_dir('pub/media/catalog/category')) {
                mkdir('pub/media/catalog/category', 0755);
            }

            $url = $this->erp_url.'/api/warehouse/productcategory';
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
                $this->updateCategoryInfo($authCode);
                $this->updateProductInfo($authCode);
                $this->_logger->info('Catalog Update is finished.');
            }
        }
    }

    public function updateProductInfo($authCode) {

        $this->_logger->info('Product Update is started.');
        $state = $this->objectManager->get('Magento\Framework\App\State');
        $state->setAreaCode('adminhtml');
        //Get Json String from API
        $url = $this->erp_url.'/api/warehouse/product?context[]=list_products&context[]=show_product&search=webProduct';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $authCode);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl);
        $result = json_decode($result);

        curl_close($curl);

        foreach ($result as $rowData) {
            $array = json_decode(json_encode($rowData), true);
            //If Category Exists, Ignore
            $sku = trim($array['code']);
            $_product = null;
            $_productCollection = $this->objectManager->create('Magento\Catalog\Model\ResourceModel\Product\Collection')->addFieldToFilter('sku',array("eq" => $sku));

            if(count($_productCollection)>0){
                $_product = $_productCollection->getFirstItem();
                $_product = $this->objectManager->create('Magento\Catalog\Model\Product')->load($_product->getId());
            } else {
                $_product = $this->objectManager->create('\Magento\Catalog\Model\Product');
                $_product->setWebsiteIds(array(1))
                    ->setAttributeSetId(4)
                    ->setSku($sku)
                    ->setTypeId('simple');
                if ($array['productImageURL'] !== '') {
                    $imageKeyArray = explode("/", $array['productImageURL']);
                    $imageId = $imageKeyArray[3];
                    if ($imageId !== '') {
                        $imageUrl = $this->erp_url."/api/core/jobdocuments/" . $imageId . "/download";
                        $imageData = curl_init($imageUrl);
                        curl_setopt($imageData, CURLOPT_USERPWD, $authCode);
                        curl_setopt($imageData, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($imageData, CURLOPT_TIMEOUT, 10);
                        curl_setopt($imageData, CURLOPT_CUSTOMREQUEST, 'GET');
                        curl_setopt($imageData, CURLOPT_HTTPGET, 1);
                        curl_setopt($imageData, CURLOPT_SSL_VERIFYPEER, false);
                        $imageBinaryData = curl_exec($imageData);
                        $fileName = $array['code'].'.jpg';
                        $imageFile = 'pub/media/import/'.$fileName;
                        file_put_contents($imageFile, $imageBinaryData);
                        $imageFile = $this->directory_list->getPath('media').'/import/'.$fileName;
                        $_product->addImageToMediaGallery($imageFile, array('image', 'small_image', 'thumbnail'), false, false);
                    }
                }
            }

            $_product->setDescription($array['outputName']);
            $_product->setShortDescription($array['outputName']);
            $_product->setName($array['name']);
            $_product->setPrice($array['salesPrice']);
            $_product->setWeight(1);
            $this->setAttributeForProduct($_product, 'hs_code', $array['HSCode']);
            $this->setAttributeForProduct($_product, 'scientific_name', $array['scientificName']);
            $measureUnitData = $array['salesUnitOfMeasure'];
            $this->setAttributeForProduct($_product, 'sales_uom', $measureUnitData['code']);
            $quantity = $this->getStockByCode($authCode,$sku);
            $_product->setStockData(array(
                'is_in_stock' => $quantity == 0 ? 0 : 1,
                'qty' => $quantity,
                'is_qty_decimal' => 1));

            $_productCategoryIds = array();
            $_productCategoryIds[] = 2;
            if (array_key_exists('productCategory', $array)) {
                $categoryData = $array['productCategory'];
                $categoryId = $this->getCategoryIdByCode($categoryData['code']);
                if ($categoryId !== 0) {
                    $_productCategoryIds[] = $categoryId;
                }
            }
            $_product->setCategoryIds($_productCategoryIds);


            //Create Sales Pricing Group

            $product_id = $array['id'];
            $tierPrices = array();

            $url = $this->erp_url.'/api/warehouse/product/'.$product_id.'/salespricinggroupproducts';

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, $authCode);

            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            $result = curl_exec($curl);
            $result = json_decode($result);

            curl_close($curl);

            foreach ($result as $rowData) {
                $array = json_decode(json_encode($rowData), true);
                if (isset($array['salesPricingGroup'])) {
                    //Create Customer Group if not exists
                    $salesPricingGroupInfo = $array['salesPricingGroup'];

                    $groupId = $this->getCustomerGroupByCode($salesPricingGroupInfo['code']);

                    if ($groupId == -1) {

                        $group = $this->objectManager->create('Magento\Customer\Model\Group');

                        $group->setCode($salesPricingGroupInfo['code'])
                            ->setTaxClassId(3) // magic numbers OK, core installers do it?!
                            ->save();
                        $groupId = $group->getId();

                    }

                    $tier_qty = $array['quantity'];
                    $tierPrices[] = array(
                        'website_id'  => 0,
                        'cust_group'  => intval($groupId),
                        'price_qty'   => $tier_qty == '0' ? 1 : intval($tier_qty),
                        'price'       => floatval($array['salesPrice'])
                    );
                }

            }

            $_product->setTierPrice($tierPrices);

            try {
                $_product->save();
            } catch (\Exception $e) {
                $this->_logger->info($e->getMessage());
                return;
            }

            $this->_logger->info('Product Update is Finished.');
        }
    }

    public function updateCategoryInfo($authCode)
    {
        $this->_logger->info('Category Update is started.');
        //Get Json String from API
        $url = $this->erp_url.'/api/warehouse/productcategory';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $authCode);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl);
        $result = json_decode($result);
        curl_close($curl);

        //Opening Each one to upload category
        $storeManager = $this->objectManager->create('\Magento\Store\Model\StoreManagerInterface');

        foreach ($result as $rowData) {
            $array = json_decode(json_encode($rowData), true);

            //If Category Exists, Ignore
            if ($this->getCategoryIdByCode($array['code']) !== 0) {
                continue;
            }

            $parentId = 2;

            //If Array contains Parent Key
            if (array_key_exists('parentProductCategory', $array)) {
                $parentCategoryData = $array['parentProductCategory'];
                $returnId = $this->getCategoryIdByCode($parentCategoryData['code']);
                if ($returnId !== 0) {
                    $parentId = $returnId;
                }
            }

            $parentCategory = $this->objectManager->create('\Magento\Catalog\Model\CategoryRepository')->get($parentId);
            $category = $this->objectManager->create('\Magento\Catalog\Model\CategoryFactory')->create()
                ->setName($array['name'])
                ->setIsActive(true)
                ->setParentId($parentId)
                ->setStoreId($storeManager->getStore()->getStoreId())
                ->setPath($parentCategory->getPath())
                ->setData('code', trim($array['code']))
                ->setData('hs_code', $array['HSCode'])
                ->setData('scientific_name', $array['scientificName']);

            //Upload Category Image
            if ($array['productCategoryImageURL'] !== '') {
                $imageKeyArray = explode("/", $array['productCategoryImageURL']);
                $imageId = $imageKeyArray[3];
                if ($imageId !== '') {
                    $imageUrl = $this->erp_url."/api/core/jobdocuments/" . $imageId . "/download";
                    $imageData = curl_init($imageUrl);
                    curl_setopt($imageData, CURLOPT_USERPWD, $authCode);
                    curl_setopt($imageData, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($imageData, CURLOPT_TIMEOUT, 10);
                    curl_setopt($imageData, CURLOPT_CUSTOMREQUEST, 'GET');
                    curl_setopt($imageData, CURLOPT_HTTPGET, 1);
                    curl_setopt($imageData, CURLOPT_SSL_VERIFYPEER, false);
                    $imageBinaryData = curl_exec($imageData);
                    $fileName = $array['code'].'.jpg';
                    $imageFile = 'pub/media/catalog/category/'.$fileName;
                    file_put_contents($imageFile, $imageBinaryData);
                    $mediaAttribute = array ('image', 'small_image', 'thumbnail');
                    $category->setImage($fileName, $mediaAttribute, true, false);
                    $category->setStoreId(0);
                }
            }

            try {
                $category->save();
            } catch (\Exception $e) {
                $this->_logger->info($e->getMessage());
                return;
            }
            $this->_logger->info('Category Update is finished.');
        }
    }

    public function getCategoryIdByCode($code)
    {
        $categoryFactory = $this->objectManager->create('Magento\Catalog\Model\ResourceModel\Category\CollectionFactory');
        $categories = $categoryFactory->create()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('code', $code);
        if (($categories == null) || count($categories) < 1) {
            return 0;
        }
        foreach ($categories as $category) {
            return $category->getId();
        }
    }

    public function getStockByCode($authCode,$code) {
        //Get Json String from API
        $url = $this->erp_url.'/api/warehouse/availableinventory/generalavailableinventory';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $authCode);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl);
        $result = json_decode($result);
        curl_close($curl);

        $array = json_decode(json_encode($result), true);
        $qtyData = $array['products'];
        foreach ($qtyData as $qtyRow) {
            if ($qtyRow['productCode'] === $code) {
                return $qtyRow['quantity'];
            }
        }
        return 0;
    }

    private function setAttributeForProduct($product, $attribute_code, $value){

        $attributeOptionManagement = $this->objectManager->create('\Magento\Eav\Api\AttributeOptionManagementInterface');
        try {
            $attribute = $this->objectManager->create('\Magento\Eav\Model\AttributeRepository')->get('catalog_product', $attribute_code);
            $attribute_id = $attribute->getAttributeId();

            if($attribute->getData('frontend_input')=='select'){
                $options = $attributeOptionManagement->getItems('catalog_product', $attribute_id);
                $optionValue = 0;
                foreach($options as $option) {
                    if ($option->getLabel() == $value) {
                        $optionValue = $option->getValue();
                        break;
                    }
                }

                if($optionValue == 0){
                    $newOption = $this->objectManager->create('\Magento\Eav\Model\Entity\Attribute\Option');
                    $attributeOptionLabel = $this->objectManager->create('\Magento\Eav\Api\Data\AttributeOptionLabelInterface');

                    $attributeOptionLabel->setStoreId(0);
                    $attributeOptionLabel->setLabel($value);
                    $newOption->setLabel($attributeOptionLabel);
                    $newOption->setStoreLabels([$attributeOptionLabel]);
                    $newOption->setSortOrder(0);
                    $newOption->setIsDefault(false);
                    $attributeOptionManagement->add('catalog_product', $attribute_id, $newOption);

                    $options_new = $attributeOptionManagement->getItems('catalog_product', $attribute_id);

                    foreach($options_new as $option_new){
                        if($option_new->getLabel() == $value){
                            $optionValue = $option_new->getValue();
                            break;
                        }
                    }
                }
                $product->setData($attribute_code, $optionValue);
            }else{
                $product->setData($attribute_code, $value);
            }
        }catch(\Magento\Framework\Exception\NoSuchEntityException $e) {
            echo("Attribute not exist! : ". $attribute_code. "\n");
        }
    }

    public function getCustomerGroupByCode($code) {
        $customerGroupCollection = $this->objectManager->get('\Magento\Customer\Model\ResourceModel\Group\Collection');
        foreach($customerGroupCollection as $group) {
            $groupId = $group->getId();
            $groupRepository  = $this->objectManager->create('\Magento\Customer\Api\GroupRepositoryInterface');
            $groupData = $groupRepository->getById($groupId);
            if ($groupData->getCode() === $code) {
                return $groupId;
            }
        }
        return -1;
    }
}
