<?php
namespace framework\driver\rpc;

use framework\util\Arr;
use framework\core\Loader;

class Grpc
{
    protected $config = [
        // 服务端点
        //'endpoint'                => null,
        // service类名前缀
        //'prefix'                  => null,
        // 简单模式，简单模式下使用CURL请求，不支持流处理
        'simple_mode'               => false,
        // 是否启用HTTP2（简单模式）
        //'enable_http2'=> false,
        // 公共headers（简单模式）
        //'headers'     => null,
        // CURL设置（简单模式）
        //'curlopts'    => null,
        // 自动绑定参数
        'auto_bind_param'           => true,
        
        //'response_to_array'       => true,
        // 请求参数协议类格式
        'request_scheme_format'     => '{service}{method}Request',
        // 响应结构协议类格式
        'response_scheme_format'    => '{service}{method}Response',
    ];
    
    protected $simple_mode;
    
    protected $request_classes;
    
    public function __construct($config)
    {
        foreach (Arr::poll($config, 'service_schemes') as $type => $rules) {
            Loader::add($type, $rules);
        }
        $this->config = $config + $this->config;
    }
    
    public function __get($name)
    {
        return $this->query($name);
    }

    public function __call($method, $params)
    {
        return $this->query()->$method(...$params);
    }
    
    public function query($name = null)
    {
        $ns = [];
        if (isset($this->config['prefix'])) {
            $ns[] = $this->config['prefix'];
        }
        if (isset($name)) {
            $ns[] = $name;
        }
        if (empty($this->config['simple_mode'])) {
            return new query\Grpc($this, $ns, $this->config);
        }
        return new query\GrpcSimple($this, $ns, $this->config);
    }
    
    public function arrayToRequest($request, $params)
    {
        return \Closure::bind(function ($params) {
            $i = 0;
            foreach (array_keys(get_class_vars(get_class($this))) as $k) {
                if (isset($params[$i])) {
                    $this->$k = $params[$i];
                    $i++;
                }
            }
            return $this;
        }, new $request, $request)($params);
    }
    
    public function responseToArray($response)
    {
        return \Closure::bind(function () {
            foreach (array_keys(get_class_vars(get_class($this))) as $k) {
                $return[$k] = $this->$k;
            }
            return $return;
        }, $response, $response)();
    }
}