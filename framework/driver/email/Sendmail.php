<?php
namespace framework\driver\email;

use framework\util\Arr;
use framework\driver\email\query\Mime;

class Sendmail extends Email
{   
    protected function init($config)
    {
        if (isset($config['sendmail_path'])) {
            ini_set('sendmail_path', $config['sendmail_path']);
        }
    }
    
    public function handle($options)
    {
        $subject = Mime::encodeHeader(Arr::pull($options, 'subject'));
        list($header, $body) = explode(Mime::EOL.Mime::EOL, Mime::build($options, $addrs), 2);
        return mail(implode(',', $addrs), $subject, $body, $header);
    }
}
