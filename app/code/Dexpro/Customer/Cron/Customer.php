<?php

namespace Dexpro\Customer\Cron;

use \Psr\Log\LoggerInterface;

class Customer {

    protected $logger;

    public function __construct(LoggerInterface $logger) {

        $this->logger = $logger;

    }

    public function execute() {
        exec('php bin/magento dexpro:customer');
    }

}