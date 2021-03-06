<?php
namespace framework\core\app;

use framework\App;
use framework\core\Loader;
use framework\core\Dispatcher;
use framework\core\http\Status;
use framework\core\http\Request;
use framework\core\http\Response;

/*
 * https://github.com/google/protobuf
 * pecl install protobuf 或者 composer require google/protobuf
 */
use Google\Protobuf\Internal\Message;

class Grpc extends App
{
    protected $config = [
        // 控制器namespace
        'controller_ns'         => 'controller',
        // 控制器类名后缀
        'controller_suffix'     => null,
        /* 参数模式
         * 0 普通参数模式
         * 1 request response 参数模式（默认）
         * 2 request response 参数模式（自定义/默认）
         */
        'param_mode'            => 0,
        // 默认调度的控制器，为空不限制
        'default_dispatch_controllers' => null,
        // 默认调度的控制器别名
        'default_dispatch_controller_aliases' => null,
        // 设置动作调度别名属性名，为null则不启用
        'action_dispatch_aliases_property' => 'aliases',
        // 闭包绑定的类（为true时绑定getter匿名类）
        'closure_bind_class' => true,
        // Getter providers（绑定getter匿名类时有效）
        'closure_getter_providers' => null,
        // service前缀
        'service_prefix'        => null,
        // schema定义文件加载规则
        'schema_loader_rules'	=> null,
        // 请求解压处理器
        'request_decode'        => ['gzip' => 'gzdecode'],
        // 响应压缩处理器
        'response_encode'       => ['gzip' => 'gzencode'],
        // 默认请求message格式
        'request_message_format'    => '{service}{method}Request',
        // 默认响应message格式
        'response_message_format'   => '{service}{method}Response',
    ];
    // 自定义服务集合
    protected $custom_methods;
	
    /*
     * 自定义服务类或实例
     */
    public function method($name, $method, $call = null)
    {
        if ($call !== null) {
			$this->custom_methods['methods'][$name][$method] = $call;
        } else {
			if (isset($this->custom_methods['methods'][$name])) {
				$this->custom_methods['methods'][$name] = $method + $this->custom_methods['methods'][$name];
	        } else {
	        	$this->custom_methods['methods'][$name] = $method;
	        }
        }
        return $this;
    }
	
    /*
     * 自定义服务类或实例
     */
    public function service($name, $class = null)
    {
        if ($class !== null) {
            $this->custom_methods['services'][$name] = $class;
        } else {
			if (isset($this->custom_methods['services'])) {
				$this->custom_methods['services'] = $name + $this->custom_methods['services'];
			} else {
				$this->custom_methods['services'] = $name;
			}
        }
        return $this;
    }
    
    /*
     * 调度
     */
    protected function dispatch()
    {
        if (count($arr = App::getPathArr()) !== 2) {
            return;
        }
		list($service, $method) = $arr;
        if ($this->config['service_prefix']) {
            $len = strlen($this->config['service_prefix']);
            if (strncasecmp($this->config['service_prefix'], $service, $len) !== 0) {
                return;
            }
            $service = substr($service, $len + 1);
        }
        if ($this->config['schema_loader_rules']) {
            foreach ($this->config['schema_loader_rules'] as $type => $rules) {
                Loader::add($type, $rules);
            }
        }
		if ($this->custom_methods) {
			$call = $this->customDispatch($method, $service);
		} else {
			$call = $this->defaultDispatch($method, $service);
		}
		if ($call) {
			return $this->dispatch = compact('method', 'service', 'call');
		}
    }
	
    /*
     * 调用
     */
    protected function call()
    {
		if ($this->config['param_mode']) {
			return $this->callWithReqResParams($this->dispatch['call']);
		} else {
			return $this->callWithParams($this->dispatch['call']);
		}
    }

    /*
     * 错误
     */
    protected function error($code = null, $message = null)
    {
        Response::headers(['grpc-status' => $code, 'grpc-message' => $message ?? Status::CODE[$code] ?? '']);
    }
    
    /*
     * 响应
     */
    protected function respond($return)
    {
        $data = $return->serializeToString();
        if ($grpc_accept_encoding = Request::header('grpc-accept-encoding')) {
            foreach (explode(',', strtolower($grpc_accept_encoding)) as $encoding) {
                if (isset($this->config['response_encode'][$encoding])) {
                    $encode = 1;
                    Response::header('grpc-encoding', $encoding);
                    $data = ($this->config['response_encode'][$encoding])($data);
                    break;
                }
            }
        }
        $size = strlen($data);
        Response::header('grpc-status', '0');
        Response::send(pack('C1N1a'.$size, $encode ?? 0, $size, $data), 'application/grpc+proto');
    }
    
    /*
     * 默认调用
     */
    protected function defaultDispatch($method, $service)
    {
		$controller = strtr($service, '.', '\\');
        if (isset($this->config['default_dispatch_controller_aliases'][$controller])) {
            $controller = $this->config['default_dispatch_controller_aliases'][$controller];
        } elseif (!isset($this->config['default_dispatch_controllers'])) {
            $check = true;
        } elseif (!in_array($controller, $this->config['default_dispatch_controllers'])) {
            return;
        }
        if (($class = $this->getControllerClass($controller, isset($check)))) {
			$instance = new $class();
			if (is_callable([$instance, $method]) && $method[0] !== '_') {
				return [$instance, $method];
			} elseif ($this->config['action_dispatch_aliases_property']) {
				return $this->actionAliasDispatch($instance, $method);
			}
        }
    }
    
    /*
     * 自定义调用
     */
    protected function customDispatch($method, $service)
    {
		if (isset($this->custom_methods['methods'][$service][$method])) {
			$call = $this->custom_methods['methods'][$service][$method];
			if ($call instanceof \Closure) {
	            if ($class = $this->config['closure_bind_class']) {
					if ($class === true) {
						$getter = getter($this->config['closure_getter_providers']);
						$call = \Closure::bind($call, $getter, $getter);
					} else {
						$call = \Closure::bind($call, new $class, $class);
					}
	            }
				return $call;
			} else {
				list($class, $action) = explode('::', Dispatcher::parseDispatch($call));
				if ($this->config['controller_ns']) {
					$class = $this->getControllerClass($class);
				}
				return [new $class, $action];
			}
		} elseif (isset($this->custom_methods['services'][$service])) {
			if (!is_object($class = $this->custom_methods['services'][$service])) {
				if ($this->config['controller_ns']) {
					$class = $this->getControllerClass($class);
				}
				$class = new $class;
			}
            if (is_callable([$class, $method]) && $method[0] !== '_') {
                return [$class, $method];
            } elseif ($this->config['action_dispatch_aliases_property']) {
				return $this->actionAliasDispatch($class, $method);
			}
		}
    }
	
    /*
     * Action 别名调度
     */
    protected function actionAliasDispatch($instance, $method)
    {
		$property = $this->config['action_dispatch_aliases_property'];
		if (isset($instance->$property[$method])) {
			return [$instance, $instance->$property[$method]];
		}
    }
	
    /*
     * 调用（普通参数模式）
     */
    protected function callWithParams($call)
    {
        list($request_class, $response_class) = $this->getDefaultReqResClass();
        if (is_subclass_of($request_class, Message::class) && is_subclass_of($response_class, Message::class)) {
            $request_message = new $request_class;
            $request_message->mergeFromString($this->readParams());
            $params = $this->bindMethodKvParams(
                $this->getReflection($call),
                json_decode($request_message->serializeToJsonString(), true)
            );
            $return = $call(...$params);
            $response_message = new $response_class;
            $response_message->mergeFromJsonString(json_encode($return));
            return $response_message;
        }
		self::abort(500, 'Illegal message schema class');
    }
    
    /*
     * 调用（request response 参数模式）
     */
    protected function callWithReqResParams($call)
    {
		list($request_class, $response_class) = $this->getDefaultReqResClass();
        if ($this->config['param_mode'] == '2') {
			$parameters = $this->getReflection($call)->getParameters();
			if (isset($parameters[0]) && $parameters[0]->hasType()) {
				$request_class = (string) $parameters[0]->getType();
			}
			if (isset($parameters[1]) && $parameters[1]->hasType()) {
				$response_class = (string) $parameters[1]->getType();
			}
        }
        if (is_subclass_of($request_class, Message::class) && is_subclass_of($response_class, Message::class)) {
            $request_message = new $request_class;
            $request_message->mergeFromString($this->readParams());
            $call($request_message, $response_message = new $response_class);
            return $response_message;
        }
		self::abort(500, 'Illegal message schema class');
    }
	
    /*
     * 读取请求参数
     */
    protected function readParams()
    {
        if (($body = Request::body()) && strlen($body) > 5) {
            extract(unpack('Cencode/Nzise/a*data', $body));
            if ($zise === strlen($data)) {
                if ($encode === 1) {
                    if (($grpc_encoding = strtolower(Request::header('grpc-encoding')))
                        && isset($this->config['request_decode'][$grpc_encoding])
                    ) {
                        return ($this->config['request_decode'][$grpc_encoding])($data);
                    }
                    self::abort(400, 'Invalid params grpc encoding');
                }
                return $data;
            }
        }
        self::abort(400, 'Invalid params');
    }
    
    /*
     * 获取request response 类（默认规则）
     */
    protected function getDefaultReqResClass()
    {
        $replace = [
            '{service}' => $this->dispatch['service'],
            '{method}'  => ucfirst($this->dispatch['method'])
        ];
        $request_class  = strtr($this->config['request_message_format'], $replace);
        $response_class = strtr($this->config['response_message_format'], $replace);
        if ($this->config['service_prefix']) {
            $request_class = $this->config['service_prefix'].'\\'.$request_class;
            $response_class = $this->config['service_prefix'].'\\'.$response_class;
        }
        return [$request_class, $response_class];
    }
	
    /*
     * 获取方法反射实例
     */
    protected function getReflection($call)
    {
		return $call instanceof \Closure ? new \ReflectionFunction($call) : new \ReflectionMethod(...$call);
    }
}
