<?php
namespace framework\driver\sms;

use framework\core\http\Client;

class Alidayu extends Sms
{
    protected static $endpoint = 'http://gw.api.taobao.com/router/rest';
    
    protected function handle($to, $template, $data, $signname = null)
    {
        $form = [
            'app_key'           => $this->acckey,
            'format'            => 'json',
            'method'            => 'alibaba.aliqin.fc.sms.num.send',
            'rec_num'           => $to,
            'sign_method'       => 'md5',
            'sms_free_sign_name'=> $signname ?? $this->signname,
            'sms_param'         => json_encode($data),
            'sms_template_code' => $this->template[$template],
            'sms_type'          => 'normal',
            'timestamp'         => date('Y-m-d H:i:s'),
            'v'                 => '2.0',
        ];
        $str = '';
        foreach ($form as $k => $v) {
            $str .= $k.$v;
        }
        $form['sign'] = strtoupper(md5($this->seckey.$str.$this->seckey));
        $client = Client::post(self::$endpoint)->form($form);
        $result = $client->response->json();
        if (isset($result['alibaba_aliqin_fc_sms_num_send_response']['result'])) {
            return true;
        }
        return error($result['error_response']['sub_msg'] ?? $result['error_response']['msg'] ?? $client->error);
    }
}