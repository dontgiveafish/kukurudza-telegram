<?php

namespace Buzz;

class Controller
{
    protected $service, $name;

    public function __construct(Service $service, $name)
    {
        $this->service = $service;
        $this->name = $name;
    }

    public function __call($action, $arguments)
    {
        $endpoint = $this->name . '/' . $action;
        $result = $this->service->call($endpoint, reset($arguments));

        return $result;
    }
}
