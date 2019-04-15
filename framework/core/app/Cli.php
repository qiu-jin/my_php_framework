<?php
namespace framework\core\app;

use framework\App;
use framework\core\Getter;
use framework\core\Command;

class Cli extends App
{
    protected $config = [
        // 控制器namespace
        'controller_ns' => 'command',
        // 控制器类名后缀
        'controller_suffix' => null,
        // 默认调用的方法，空则调用__invoke
        'default_method' => null,
        // 匿名函数是否启用Getter魔术方法
        'closure_enable_getter' => true,
        // Getter providers
        'closure_getter_providers' => null,
    ];
    // 核心错误
    protected $core_errors = [
        404 => 'Bad Request',
        404 => 'Method not found',
        500 => 'Internal Server Error',
    ];
    // 自定义方法集合
    protected $custom_methods;
    
    /*
     * 设置命令行调用
     */
    public function command($name, $call = null)
    {
        if ($call !== null) {
            $this->custom_methods['commands'][$name] = $call;
        } elseif (is_array($name)) {
			if (isset($this->custom_methods['commands'])) {
				$this->custom_methods['commands'] = $name + $this->custom_methods['commands'];
			} else {
				$this->custom_methods['commands'] = $name;
			}
        } else {
            $this->custom_methods['command'] = $name;
        }
        return $this;
    }
    
    /*
     * 调度
     */
    protected function dispatch()
    {
        if (!App::IS_CLI) {
            throw new \RuntimeException('NOT CLI SAPI');
        }	
		$arguments = $this->parseArguments();
		if ($this->custom_methods) {
			$call = $this->customDispatch($arguments);
		} else {
			$call = $this->defaultDispatch($arguments);
		}
		if ($call) {
			return $this->dispatch = [
				'call' => $call,
				'params' => $arguments['params'] ?? [],
			];
		}
    }
    
    /*
     * 调用
     */
    protected function call()
    {
		return $this->dispatch['call'](...$this->dispatch['params']);
    }
	
    /*
     * 默认调度
     */
    protected function defaultDispatch($arguments)
    {
		if (empty($arguments['params'])) {
			return;
		}
		$controller = strtr(array_shift($arguments['params']), ':', '\\');
		if ($class = $this->getControllerClass($controller)) {
			$call = new $class($arguments);
            if ($this->config['default_method']) {
                $call = [$call, $this->config['default_method']];
            }
			return $call;
		}
    }
    
    /*
     * 自定义调度
     */
    protected function customDispatch(&$arguments)
    {
		if (isset($this->custom_methods['command'])) {
			$call = $this->custom_methods['command'];
		} else {
			if (empty($arguments['params'])) {
				return;
			}
			$name = array_shift($arguments['params']);
			if (empty($this->custom_methods['commands'][$name])) {
				return;
			}
			$call = $this->custom_methods['commands'][$name];
		}
        if ($call instanceof \Closure) {
            if (!$this->config['closure_enable_getter']) {
                $command = new class ($arguments) extends Command {};
            } else {
                $providers = $this->config['closure_getter_providers'];
                $command = new class ($providers, $arguments) extends Command {
                    use Getter;
                    public function __construct($providers, $arguments) {
                        if ($providers) {
                            $this->{\app\env\GETTER_PROVIDERS_NAME} = $providers;
                        }
                        parent::__construct($arguments);
                    }
                };
            }
            return \Closure::bind($call, $command, Command::class);
        } elseif (is_string($call)) {
			if ($this->config['controller_ns']) {
				$call = $this->getControllerClass($call);
			}
            $call = new $call($arguments);
            if ($this->config['default_method']) {
                $call = [$call, $this->config['default_method']];
            }
			return $call;
        }
    }
    
    /*
     * 错误
     */
    protected function error($code = null, $message = null)
    {
        $command = $this->dispatch['instance'] ?? new Command;
        $command->error("[$code]");
        $command->highlight(is_array($message) ? var_export($message, true) : $message);
    }
    
    /*
     * 响应
     */
    protected function respond($return = null)
    {
        self::exit(2);
        exit((int) $return);
    }
    
    /*
     * 解析命令行参数
     */
    protected function parseArguments()
    {
        $argv = $_SERVER['argv'];
        array_shift($argv);
		$count = count($argv);
        $last_option = null;
        for ($i = 0; $i < $count; $i++) {
            if (strpos($argv[$i], '-') !== 0) {
                if ($last_option) {
                   	$arguments["$last_option[0]_options"][$last_option[1]] = $argv[$i];
                    $last_option = null;
                } else {
                    $arguments['params'][] = $argv[$i];
                }
            } else {
                $last_option = null;
                if (strpos($argv[$i], '--') === 0) {
                    if ($option_name = substr($argv[$i], 2)) {
                        if (strpos($option_name, '=') > 0) {
                            list($k, $v) = explode('=', $option_name, 2);
                            $arguments['long_options'][$k] = $v;
                        } else {
                            $arguments['long_options'][$option_name] = true;
                            $last_option = ['long', $option_name];
                        }
                    }
                } else {
                    if ($option_name = substr($argv[$i], 1)) {
                        if (isset($option_name[1])) {
                            $arguments['short_options'][$option_name[0]] = substr($option_name, 1);
                        } elseif (isset($option_name[0])) {
                            $arguments['short_options'][$option_name] = true;
                            $last_option = ['short', $option_name];
                        }
                    }
                }
            }
        }
		return $arguments ?? [];
    }
}

