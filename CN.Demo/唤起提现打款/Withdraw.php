<?php


public function poc_dakuan()
{
    if (request()->isPost()) {
        if (input('post.id')) {
            if (!input('post.pin')) {
                $this->error('请输入PIN');
            }
            $txs = Db::name('wallet_withdraw')->where('id', input('post.id'))->where('status', 2)->find();
            if (!$txs) {
                $this->error('此记录状态不适用该操作');
            }
            if ($txs['way'] != 'TRC20') {
                $this->error('当前仅支持TRC20 USDT提现');
            }

            //-----------检查打款状态---------
            //0未提交1正在处理2处理成功3处理失败
            if ($txs['dk_type'] = 1 && !in_array($txs['dk_status'], [0, 3, 4])) {
                $this->error('记录的通道状态不允许提交');
            }

            //-----------初始化sdk---------
            $config = Db::name('pocpay_config')
                ->where('id', 1)->find();
            if (!$config) {
                $this->error('暂不支持该功能');
            }
            $api = new PocPaySDK(
                $config['gateway_url'],
                $config['app_id'],
                $config['app_key']
            );

            //----------获得会员钱包地址---------

            $member = Db::name('member')->where('id', $txs['user_id'])->field('id, recode, email, user_name, wallet_address')->find();
            if (empty($member['wallet_address'])) {
                try {
                    $res = $api->createTokenWallet($member['recode'], $member['email'], $member['user_name']);
                } catch (\Exception $ex) {
                    $this->error('暂不支持');
                }
                if ($res['code'] != '10000') {
                    $this->error('获取会员信息失败');
                }
                $wallet_address = $res['data']['wallet_address'];
                //绑定钱包地址
                Db::name('member')->where('id', $txs['user_id'])->update(['wallet_address' => $wallet_address]);
            } else {
                $wallet_address = $member['wallet_address'];
            }

            // 启动事务
            Db::startTrans();
            try {
                //------------生成打款账单---------

                $orderNumber = 'Z' . date('YmdHis') . substr(implode(NULL, array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8);
                $zong_id = db('dk_zong')->insertGetId([
                    'user_id' => $txs['user_id'],
                    'order_number' => $orderNumber,
                    'amount' => $txs['amount'],
                    'dk_type' => 1,
                    'addtime' => time(),
                    'time_out' => time() + 1*60*60,//一个小时内需要处理完成
                ]);

                Db::name('wallet_withdraw')->where('id', input('post.id'))->update(array(
                    'zong_id' => $zong_id,
                    'dk_type' => 1, //0手工1POC
                    'dk_status' => 1, //已提交
                ));

                // 提交事务
                Db::commit();
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                return json(['status' => 0, 'mess' => $ex->getMessage()]);
            }

            try {
                //------------转账---------
                $api->member_wallet_address = $wallet_address; //设置接口的注册会员

                $res = $api->submitTokenWithdraw(
                    $txs['kyc_value'], //接收地址
                    $txs['amount'], //到账金额
                    input('post.pin'),
                    $orderNumber
                );
                if ($res['code'] != '10000') {
                    //恢复状态
                    Db::name('wallet_withdraw')->where('id', input('post.id'))->update(array(
                        'zong_id' => $zong_id,
                        'dk_type' => 1, //0手工1POC
                        'dk_status' => 0, 
                    ));

                    throw new \Exception($res['sub_msg']);
                }

                //成功就改状态，失败就不需要
                Db::name('wallet_withdraw')->where('id', input('post.id'))->update(array(
                    'status' => 3,
                    'updated_at' => time(),
                    'txid' => $res['data']['txid'],
                    'dk_status' => 2, //打款通道处理状态(0未提交1正在处理2处理成功3处理失败)
                ));

                db('dk_zong')->where('id', $zong_id)->update([
                    'state'=>1,
                    'dk_time'=>time(),                        
                ]);

                ys_admin_logs('打款成功', 'wallet_withdraw', input('post.id'));
                $value = array('status' => 1, 'mess' => '打款成功');
            } catch (\Exception $ex) {
                return json(['status' => 0, 'mess' => $ex->getMessage()]);
            }
        } else {
            $value = array('status' => 0, 'mess' => '参数错误');
        }
        return json($value);
    } else {
        $this->error('缺少参数');
    }
}

