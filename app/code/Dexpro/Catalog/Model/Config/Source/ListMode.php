<?php

namespace Dexpro\Catalog\Model\Config\Source;

class ListMode implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => '1', 'label' => __('1 Hour')],
            ['value' => '6', 'label' => __('6 Hours')],
            ['value' => '12', 'label' => __('12 Hours')],
            ['value' => '24', 'label' => __('1 Day')]
        ];
    }
}