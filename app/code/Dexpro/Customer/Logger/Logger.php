<?php
namespace Dexpro\Customer\Logger;

class Logger extends \Monolog\Logger {
    protected $name;

    public function __construct($name, array $handlers = array(), array $processors = array())
    {
        $this->name = $name;
        parent::__construct($name,$handlers,$processors);
    }
}