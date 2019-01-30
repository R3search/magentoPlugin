<?php

namespace Dexpro\Catalog\Cron;

use \Psr\Log\LoggerInterface;

class Catalog {

    protected $logger;

    public function __construct(LoggerInterface $logger) {

        $this->logger = $logger;

    }

    public function execute() {
        exec('php bin/magento dexpro:catalog');
    }

}