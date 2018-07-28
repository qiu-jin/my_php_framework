<?php
namespace framework\driver\cache;

use framework\core\Event;

abstract class Cache
{
    protected $serializer;
    protected $gc_maxlife;
    
    abstract public function get($key, $default);
    
    abstract public function set($key, $value, $ttl);
    
    abstract public function has($key);
    
    abstract public function delete($key);
    
    abstract public function clean();
    
    public function __construct($config)
    {
        $this->init($config);
        if (isset($config['gc_random'])
            && method_exists($this, 'gc')
            && mt_rand(1, $config['gc_random'][1]) <= $config['gc_random'][0]
        ) {
            $this->gc_maxlife = $config['gc_maxlife'] ?? 2592000;
            Event::on('close', [$this, 'gc']);
        }
        isset($config['serializer']) && $this->serializer = $config['serializer'];
    }
    
    public function getMultiple(array $keys, $default = null)
    {
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }
        return $values;
    }
    
    public function setMultiple(array $values, $ttl = null)
    {
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                return false;
            }
        }
        return true;
    }
    
    public function deleteMultiple(array $keys)
    {
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                return false;
            }
        }
        return true;
    }
    
    public function pull($key)
    {
        $value = $this->get($key);
        $this->delete($key);
        return $value;
    }
    
    public function remember($key, $value, $ttl = null)
    {
        if (!$this->has($key)) {
            if ($value instanceof \Closure) {
                $value = call_user_func($value);
            }
            $this->set($key, $value, $ttl);
        } else {
            $value = $this->get($key);
        }
        return $value;
    }
    
    protected function serialize($data)
    {
        return $this->serializer ? ($this->serializer[0])($data) : $data;
    }
    
    protected function unserialize($data)
    {
        return $this->serializer ? ($this->serializer[1])($data) : $data;
    }
}
