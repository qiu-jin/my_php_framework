<?php
namespace framework\core\app;

use framework\App;
use framework\core\Config;
use framework\core\Getter;
use framework\core\http\Status;
use framework\core\http\Request;
use framework\core\http\Response;
use framework\core\View as CoreView;

class View extends App
{
    protected $config = [
        // 调度模式，支持default route组合
        'dispatch_mode'     => ['default'],
        // 视图模型名称空间
        'viewmodel_ns'      => 'viewmodel',
        // 视图初始变量
        'boot_vars_call'    => null,
        // 是否启用pjax
        'enable_pjax'       => false,
        // 默认调度的视图，为空不限制
        'default_dispatch_views' => null,
        // 默认调度的缺省调度
        'default_dispatch_index' => null,
        // 默认调度时是否将URL PATH中划线转成下划线
        'default_dispatch_hyphen_to_underscore' => false,
        // 路由调度的路由表
        'route_dispatch_routes' => null,
    ];
    
    protected function dispatch()
    {
        $path = trim(Request::path(), '/');
        foreach ($this->config['dispatch_mode'] as $mode) {
            if ($dispatch = $this->{$mode.'Dispatch'}($path)) {
                return $dispatch;
            }
        }
        return false;
    }
    
    protected function call()
    {
        ob_start();
        $type = $this->config['enable_pjax'] && Response::isPjax() ? 'block' : 'file';
        $vars = isset($this->config['boot_vars_call']) ? $this->config['boot_vars_call']() : [];
        (\Closure::bind(function($__file, $__vars) {
            extract($__vars, EXTR_SKIP);
            return require($__file);
        }, new class() {
            use Getter;
        }))(CoreView::{$type}($this->dispatch['view']), $vars);
        return ob_get_clean();
    }
    
    protected function error($code = null, $message = null)
    {
        if (isset(Status::CODE[$code])) {
            Response::status($code);
        }
        Response::html(CoreView::error($code, $message), false);
    }
    
    protected function response($return)
    {
        Response::html($return, false);
    }
    
    protected function defaultDispatch($path) 
    {
        if ($path) {
            if ($this->config['default_dispatch_hyphen_to_underscore']) {
                $path = strtr($path, '-', '_');
            }
            if (!isset($this->config['default_dispatch_views'])) {
                if (preg_match('/^[\w\-]+(\/[\w\-]+)*$/', $path)) {
                    $view = Config::get('view.dir', APP_DIR.'view/').$path;
                    if (is_php_file("$view.php")
                        || (Config::has('view.template') && is_file(CoreView::getTemplateFile($path, true)))
                    ) {
                        return ['view' => $path];
                    }
                }
            } elseif (in_array($path, $this->config['default_dispatch_views'], true)) {
                return ['view' => $path];
            }
        } elseif (isset($this->config['default_dispatch_index'])) {
            return ['view' => $this->config['default_dispatch_index']];
        }
    }
    
    protected function routeDispatch($path)
    {
        if (!empty($this->config['route_dispatch_routes'])) {
            if (is_string($routes = $this->config['route_dispatch_routes'])) {
                $routes = Config::flash($routes);
            }
            $path = empty($path) ? null : explode('/', $path);
            if ($result = Router::route($path, $routes)) {
                return ['view' => $result[0], 'params' => $result[1]];
            }
        }
    }
}
