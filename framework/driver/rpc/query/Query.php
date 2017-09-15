<?php
namespace framework\driver\rpc\query;

class Query
{
    protected $ns;
    protected $rpc;
    protected $client_methods;
    
    public function __construct($rpc, $name, $client_methods = null)
    {
        $this->rpc = $rpc;
        $this->client_methods;
        if ($name) {
            $this->ns[] = $name;
        }
    }

    public function __get($name)
    {
        $this->ns[] = $name;
        return $this;
    }
    
    public function __call($method, $params = [])
    {
        return $this->rpc->call($this->ns, $method, $params, $this->client_methods);
    }
}