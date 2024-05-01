<?php

namespace app\common;

use GuzzleHttp\Client;

use function GuzzleHttp\json_decode;

class PocPaySDK
{
    protected $base_uri;
    protected $app_id;
    protected $app_key;
    public $member_wallet_address;

    protected $client;

    public function __construct(
        $base_uri,
        $app_id = NULL,
        $app_key = NULL,
        $member_wallet_address = NULL
    ) {
        $this->client = new Client([
            // Base URI is used with relative requests
            // 'base_uri' => $base_uri,
            // You can set any number of default request options.
            'timeout'  => 10.0,
        ]);

        $this->base_uri = $base_uri;
        $this->app_id = $app_id;
        $this->app_key = $app_key;
        $this->member_wallet_address = $member_wallet_address;
    }

    /**
     * 附加签名
     */
    protected function withSign($params)
    {
        $params['app_id'] = $this->app_id;
        if ($this->member_wallet_address) {
            $params['member_wallet_address'] = $this->member_wallet_address;
        }
        $str = $this->getSignContent($params);
        $sign = md5($str . $this->app_key);
        $params['sign'] = $sign;
        return $params;
    }

    /**
     * 创建会员钱包
     */
    public function createTokenWallet(
        $out_user_id,
        $out_user_email,
        $out_user_name
    ) {
        try {
            $response = $this->client->post($this->base_uri.'/createTokenWallet', [
                'form_params' => $this->withSign([
                    'out_user_id' => $out_user_id,
                    'out_user_email' => $out_user_email,
                    'out_user_name' => $out_user_name
                ]), //参考：https://docs.guzzlephp.org/en/stable/request-options.html#query
            ]);
        } catch (\Exception $ex) {
            return [
                'code' => '40009',
                'msg' => 'API exception',
                'sub_code' => 'isv.api-exception',
                'sub_msg' => $ex->getMessage(),
            ];
        }
        $body = $response->getBody();
        $res = json_decode($body, true);
        return $res;
    }

    /**
     * 提现
     */
    public function submitTokenWithdraw(
        $to_wallet_address,
        $amount,
        $pin='',
        $out_trade_no=NULL
    ) {
        try {
            $response = $this->client->post($this->base_uri.'/submitTokenWithdraw', [
                'form_params' => $this->withSign([
                    'to_wallet_address' => $to_wallet_address,
                    'amount' => $amount,
                    'pin' => $pin,
                    'out_trade_no' => $out_trade_no,
                ]), //参考：https://docs.guzzlephp.org/en/stable/request-options.html#query
            ]);
        } catch (\Exception $ex) {
            return [
                'code' => '40009',
                'msg' => 'API exception',
                'sub_code' => 'isv.api-exception',
                'sub_msg' => $ex->getMessage(),
            ];
        }
        $body = $response->getBody();
        $res = json_decode($body, true);
        return $res;
    }


    /**
     * 校验$value是否非空
     *  if not set ,return true;
     *    if is null , return true;
     **/
    protected function checkEmpty($value)
    {
        if (!isset($value))
            return true;
        if ($value === null)
            return true;
        if (trim($value) === "")
            return true;

        return false;
    }

    protected function getSignContent($params)
    {
        ksort($params);

        $stringToBeSigned = "";
        $i = 0;
        foreach ($params as $k => $v) {
            if (false === $this->checkEmpty($v) && "@" != substr($v, 0, 1)) {

                if ($i == 0) {
                    $stringToBeSigned .= "$k" . "=" . "$v";
                } else {
                    $stringToBeSigned .= "&" . "$k" . "=" . "$v";
                }
                $i++;
            }
        }

        unset($k, $v);
        return $stringToBeSigned;
    }

}
