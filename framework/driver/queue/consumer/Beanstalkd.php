<?php
namespace framework\driver\queue\consumer;

class Beanstalkd extends Consumer
{   
    protected function init($connection)
    {
        $connection->watch($this->job);
        $this->queue = $connection;
    }
    
    public function bpop()
    {
        if ($job = $this->queue->reserve()) {
            $message = $this->unserialize($job->getData());
            $this->queue->delete($job);
            return $message;
        }
    }
    
    public function consume(callable $call)
    {
        while (true) {
            if ($job = $this->queue->reserve()) {
                $message = $this->unserialize($job->getData());
                if ($call($message)) {
                    $this->queue->delete($job);
                } else {
                    $this->queue->release($job);
                }
            }
        }
    }
}
