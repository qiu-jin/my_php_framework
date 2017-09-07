<?php
namespace framework\core;

trait Getter
{
    public function __get($name)
    {
        if (isset($this->providers) && isset($this->providers[$name])) {
            $value = $this->providers[$name];
            if (is_string($value)) {
                return $this->$name = Container::make($value);
            } elseif (is_array($value)) {
                $class = array_shift($value);
                return $this->$name = new $class(...$value);
            } elseif (is_callable($value)) {
                return $this->$name = $value();
            }
        } elseif ($object = Container::make($name)) {
            return $this->$name = $object;
        }
        throw new \exception\GetterException('Undefined property: '.__CLASS__.'::$'.$name, 'GetterException');
    }
}
