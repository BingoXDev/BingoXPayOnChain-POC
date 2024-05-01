<?php

//----------获得付款码---------
                
                $member = Db::name('member')->where('id', $userId)->field('id, recode, email, user_name, wallet_address')->find();
                if(empty($member['wallet_address'])){
                    //开通Payonchain钱包
                    $config = Db::name('pocpay_config')->where('id',1)->find();
                    if(!$config){
                        datamsg(400, lang('通道未配置好'));
                    }
                    $api = new PocPaySDK(
                        $config['gateway_url'],
                        $config['app_id'],
                        $config['app_key']
                    );
                    try{
                        $res = $api->createTokenWallet($member['recode'],$member['email'],$member['user_name']);
                    }catch(\Exception $ex){
                        datamsg(400, lang('暂不支持'));
                    }
                    if ($res['code'] != '10000') {
                        datamsg(400, lang('获取付款码失败'),$res);
                    }
                    $wallet_address = $res['data']['wallet_address'];
                    //绑定钱包地址
                    Db::name('member')->where('id', $userId)->update(['wallet_address'=>$wallet_address]);
                }else{
                    $wallet_address = $member['wallet_address'];
                }

                //-------订单标注上支付方式以便回溯----------------

                if ($scene == 'recharge') { // 充值订单
                    Db::name('recharge_order')->where('order_number', $orderNumber)->update(['pay_way' => $zf_type]);
                } elseif ($scene == 'goods') { // 商品订单
                    $zongData['zf_type'] = $zf_type;
                    db('order_zong')->where('order_number', $orderNumber)->update($zongData);
                    $orderZongId = db('order_zong')->where('order_number', $orderNumber)->value('id');
                    db('order')->where('zong_id', $orderZongId)->update($zongData);
                } elseif ($scene == 'apply') { // 商家入驻订单
                    $rzData['zf_type'] = $zf_type;
                    db('rz_order')->where('ordernumber', $orderNumber)->update($rzData);
                } elseif ($scene == 'supplierApply') { // 供货商入驻订单
                    $rzData['zf_type'] = $zf_type;
                    db('supplier_rz_order')->where('ordernumber', $orderNumber)->update($rzData);
                }

                //-------返回---------------------------
                $data['wallet_address'] = $wallet_address;
                $data['money'] = round($orderPrice * $currency, 2);
                //$orderSn - order表的单号, $orderNumber - 付款单的单号
                datamsg(200, '创建订单成功', array('order_number' => $orderNumber, 'infos' => $data));