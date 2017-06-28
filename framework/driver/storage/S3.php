<?php
namespace framework\driver\storage;

use framework\util\File;
use framework\core\http\Client;

class S3 extends Storage
{
    protected $bucket;
    protected $region;
    protected $acckey;
    protected $seckey;
    protected $public_read = false;
    protected static $host = 's3.amazonaws.com';
    
    public function __construct($config)
    {
        $this->bucket = $config['bucket'];
        $this->acckey = $config['acckey'];
        $this->seckey = $config['seckey'];
        $this->region = $config['region'];
    }
    
    public function get($from, $to = null)
    {
        $methods['timeout'] = 30;
        if ($to) {
            $methods['save'] = $to;
        }
        return $this->send('GET', $from, null, $methods, !$this->public_read);
    }
    
    public function put($from, $to, $is_buffer = false)
    {
        $client_methods['timeout'] = 30;
        $headers['Content-Type'] = File::mime($from, $is_buffer);
        if ($is_buffer) {
            $methods['body'] = $from;
            $headers['Content-Length'] = strlen($from);
            $headers['X-Amz-Content-Sha256'] = hash('sha256', $from);
            return $this->send('PUT', $to, $headers, $methods);
        }
        $fp = fopen($from, 'r');
        if ($fp) {
            $methods['stream'] = $fp;
            $headers['Content-Length'] = filesize($from);
            $headers['X-Amz-Content-Sha256'] = hash_file('sha256', $from);
            $return = $this->send('PUT', $to, $headers, $methods);
            fclose($fp);
            return $return;
        }
        return false;
    }

    public function stat($from)
    {
        $stat = $this->send('HEAD', $from, null, ['returnHeaders' => true], !$this->public_read);
        return $stat ? [
            'type'  => $stat['Content-Type'],
            'size'  => $stat['Content-Length'],
            'mtime' => strtotime($stat['Last-Modified']),
        ] : false;
    }
    
    public function copy($from, $to)
    {
        return $this->send('PUT', $to, ['X-Amz-Copy-Source' => '/'.$this->bucket.$this->path($from)]);
    }
    
    public function move($from, $to)
    {
        return $this->copy($from, $to) && $this->delete($from);
    }
    
    public function delete($from)
    {
        return $this->send('DELETE', $from);
    }
    
    protected function send($method, $path, $headers = [], $client_methods = [], $auth = true)
    {
        $path = $this->path($path);
        $client = new Client($method, "https://".self::$host."/$this->bucket$path");
        if ($client_methods) {
            foreach ($client_methods as $client_method => $params) {
                $client->$client_method(... (array) $params);
            }
        }
        if ($auth) {
            $client->headers($this->setHeaders($method, $path, $headers));
        }
        $status = $client->status;
        if ($status >= 200 && $status < 300) {
            switch ($method) {
                case 'GET':
                    return $client->body;
                case 'PUT':
                    return true;
                case 'HEAD':
                    return $client->headers;
                case 'DELETE':
                    return true;
            }
        }
        if ($status === 404 && $method === 'HEAD') {
            return false;
        }
        $data = $client->xml;
        return error(isset($data['Message']) ? $data['Message'] : $client->error, 2);
    }
    
    protected function setHeaders($method, $path, $headers)
    {
        $headers['Host'] = self::$host;
        $headers['X-Amz-Date'] = gmdate('Ymd\THis\Z');
        if (empty($headers['X-Amz-Content-Sha256'])) {
            $headers['X-Amz-Content-Sha256'] = hash('sha256', '');
        }
        ksort($headers);
        $tmparr = [];
        $sendheaders = [];
        $canonicalheaders = '';
        foreach ($headers as $k => $v) {
            $sendheaders[] = "$k: $v";
            $k = strtolower($k);
            $v = trim($v);
            $tmparr[] = $k;
            $canonicalheaders .= "$k:$v\n";
        }
        $signheaders = implode(';', $tmparr);
        $str = "$method\n/$this->bucket$path\n\n$canonicalheaders\n$signheaders\n".$headers['X-Amz-Content-Sha256'];
        $date = substr($headers['X-Amz-Date'], 0, 8);
        $scope = "$date/$this->region/s3/aws4_request";
        $signstr = "AWS4-HMAC-SHA256\n{$headers['X-Amz-Date']}\n$scope\n".hash('sha256', $str);

        $datekey    = hash_hmac('sha256', $date, "AWS4$this->seckey", true);
        $regionkey  = hash_hmac('sha256', $this->region, $datekey, true);
        $servicekey = hash_hmac('sha256', 's3', $regionkey, true);
        $signkey    = hash_hmac('sha256', 'aws4_request', $servicekey, true);
        $signature  = hash_hmac('sha256', $signstr, $signkey);
        $sendheaders[] = "Authorization: AWS4-HMAC-SHA256 Credential=$this->acckey/$scope,SignedHeaders=$signheaders,Signature=$signature";
        return $sendheaders;
    }
}
