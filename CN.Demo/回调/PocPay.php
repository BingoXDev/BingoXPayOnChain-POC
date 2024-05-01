<?php

namespace app\api\controller;

use think\Db;
use app\api\controller\Common;
use app\api\model\RechargeOrder as RechargeOrderModel;
use Exception;

class PocPay extends Common
{
    const NOTIFY_DEPOSIT = 1;
    const NOTIFY_WITHDRAW = 2;
    //
    const POC_PAY = 11; // pocPay 的p ay_type

    /**
     * 也就是 NotifyURL对应的方法
    */
    public function callback()
    {
        $data = $_POST;
        $return = $this->payReturn($data);
        if (!$return) {
            echo 'Invalid signature!';
            die;
        }

        $txid = input('post.txid');

        //避免重复处理
        $flag = db('pocpay_txid')->where(['txid' => $txid])->count();
        if ($flag) {
            echo 'Already handled before!';
            die;
        }

        $notify_type = input('notify_type');
        switch ($notify_type) {
            case self::NOTIFY_DEPOSIT:
                $to_wallet_address = input('to_wallet_address');
                $amount = input('amount');

                //会员
                $member = db('member')->where('wallet_address', $to_wallet_address)->find();
                if (!$member) {
                    echo 'Ignore wallet_address!';
                    die;
                }

                //匹配24小时内金额相同的未付款有效的商品订单
                /*
                $order_number = db('order')->where([
                    'user_id' => $member['id'],
                    'zf_type' => 11, //pocPay
                    'total_price' => $amount,
                    'state' => 0, //待支付
                    'order_status' => 0, //未取消
                ])->where('addtime', '>', time() - 24 * 60 * 60)
                    ->order('id', 'desc')
                    ->value('ordernumber');
                */
                $order_number = db('order_zong')->where([
                    'user_id' => $member['id'],
                    'zf_type' => 11, //pocPay
                    'total_price' => $amount,
                    'state' => 0, //待支付
                ])->where('addtime', '>', time() - 24 * 60 * 60)
                    ->where('time_out', '>', time())
                    ->order('id', 'desc')
                    ->value('order_number');

                Db::startTrans();
                try {
                    if (empty($order_number)) { //没有匹配到的商品订单
                        $tag = 'handle_recharge';
                        $order_number = $this->create_recharge_order($member['id'], $amount); //创建等额充值订单
                    }else{
                        $tag = 'handle_order';
                    }
                    $this->handle_order($order_number, $amount, $txid);

                    //已处理的交易
                    Db::name('pocpay_txid')->insert(['txid' => $txid, 'tag' => $tag, 'created_at' => time()]);

                    // 提交事务
                    Db::commit();

                    echo 'SUCCESS';
                    die;
                } catch (\Exception $ex) {
                    // 回滚事务
                    echo $ex->getMessage();
                    die;
                }
                break;
            case self::NOTIFY_WITHDRAW:
                $out_trade_no = input('out_trade_no', ''); //提现单号
                Db::startTrans();
                try {
                    $this->handle_withdraw($out_trade_no, $txid);

                    //已处理的交易
                    Db::name('pocpay_txid')->insert(['txid' => $txid, 'tag' => 'handle_withdraw', 'created_at' => time()]);

                    // 提交事务
                    Db::commit();

                    echo 'SUCCESS';
                    die;
                } catch (\Exception $ex) {
                    // 回滚事务
                    echo $ex->getMessage();
                    die;
                }
                break;
        }
    }

    //提现打款的回调
    private function handle_withdraw($out_trade_no, $txid)
    {
        $zong_id = Db::name('dk_zong')->where('order_number', $out_trade_no)->value('id');

        Db::name('dk_zong')->where('order_number', $out_trade_no)->update(['state'=>1,'pay_time'=>time()]);

        //成功就改状态，失败就不需要
        Db::name('wallet_withdraw')->where('zong_id', $zong_id)
            ->where('status', 2)
            ->where('dk_status', 'in', [0,3,4])->update(array(
            'status' => 3,
            'updated_at' => time(),
            'dk_type' => 1, //0手工1POC
            'txid' => $txid,
            'dk_status' => 2,//打款通道处理状态(0未提交1正在处理2处理成功3处理失败
        ));
    }


    //会员订单支付的回调
    private function handle_order($order_number, $amount, $txid)
    {
        $data['out_trade_no'] = $order_number;
        $data['total_amount'] = $amount;
        $data['transaction_id'] = $txid;

        $order_sn = $data['out_trade_no'];  //订单单号

        $pay = new Pay();
        $orderType = substr($data['out_trade_no'], 0, 1);
        // $orderType Z-商品订单，C-充值订单，R-商家入驻保证金订单
        switch ($orderType) {
            case "Z":
                $pay->doGooodsOrder($order_sn, self::POC_PAY);
                break;
            case "C":
                $pay->doRechargeOrder($order_sn, self::POC_PAY);
                break;
            case "R":
                $pay->doRzOrder($order_sn, self::POC_PAY);
                break;
            case "S":
                $pay->doSupplierRzOrder($order_sn, self::POC_PAY);
                break;
            default:
                // 系统错误，未获取到订单类型
        }

        //付款记录表
        $op_id = Db::name('order_payment')->where('out_trade_no', $data['out_trade_no'])->value('id');
        if (empty($op_id)) {
            Db::name('order_payment')->insert(array(
                'pay_type' => self::POC_PAY,
                'out_trade_no'   => $data['out_trade_no'],
                'total_fee' => $data['total_amount'],
                'transaction_id'    => $data['transaction_id'],
                'transaction_hash'    => '',
                'addtime'    => time()
            ));
        }
    }


    /***
     * 生成唯一订单号
     */
    private function makeOrderNum()
    {
        $uonid = uniqid();
        $order_number = 'C' . time() . $uonid; // 充值订单以大写字母C开头，请勿修改
        return $order_number;
    }

    public function create_recharge_order($userId, $price)
    {
        $data['order_number'] = $this->makeOrderNum() . $userId;
        $data['order_price'] = $price;
        $data['pay_status'] = 0;
        $data['uid'] = $userId;

        $rechargeOrderModel = new RechargeOrderModel();
        $result = $rechargeOrderModel->save($data);
        if ($result) {
            return $data['order_number'];
        }
        throw new Exception("Failed to create recharge order!");
    }


    protected function payReturn($data)
    {
        $sign = $data['sign'];
        if (isset($data['sign'])) {
            unset($data['sign']);
        }

        //计算签名
        $app_key = Db::name('pocpay_config')
            ->where('id', 1)->value('app_key');
        $str = $this->getSignContent($data);
        $sign1 = md5($str . $app_key);

        if ($sign != $sign1) {
            return 0;
        }
        return 1;
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
