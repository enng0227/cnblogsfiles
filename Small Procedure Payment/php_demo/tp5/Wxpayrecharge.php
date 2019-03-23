<?php
namespace app\api\controller;


use think\Controller;
use think\Config;
use think\Db;
use app\api\model\Indent;
use app\api\model\Issue;
use think\Request;

class Wxrecharge extends Controller
{
    protected function _initialize()
    {
        /**
         * 为了数据安全传输起见  我设置了POST请求方式       //request()->isXxx()
         */
        if (!request()->isPost()) {
            self::return_err('error request method');
        }
        //微信支付参数配置(appid,商户号,支付秘钥)
        $config = array(
            'appid'              => Config::get('appid'),                        //（此处直接调用  config.php 的参数）  使用   $this->config['appid'];   获取参数值
            'app_secret'    => Config::get('app_secret'),
            'pay_mchid'    => Config::get('pay_mchid'),
            'pay_apikey'    => Config::get('pay_apikey'),
            'notify_url'      => Config::get('notify_urls'),
            'login_url'        => Config::get('login_url'),
        );
        $this->config = $config;
    }

    /**
     * 获取微信用户的OpenID
     */
    public function getOpenID()
    {
        if (!request()->isPost()) {
            self::return_err('error request method');
        } else {
            $status = 0;
            $code = input('code') ? input('code') : 0;
            $config = $this->config;
            $wxLoginUrl = sprintf($config['login_url'],$config['appid'], $config['app_secret'], $code);  //把百分号（%）符号替换成一个作为参数进行传递的变量：
            $result = self::curl_get($wxLoginUrl);
            $wxResult = json_decode($result, true);     //将json 转化为 php变量
            if($wxResult['openid']){
                $status = 1;
            };
            if ($status){
                self::return_data($wxResult);
            }else{
                self::return_err("获取openID失败！");
            }
        }
    }


    /**
     * 预支付请求接口(POST)
     * @param string $openid openid
     * @param string $body 操作简单描述
     * @param string $order_sn 订单编号
     * @param string $total_fee 金额
     * @return  json的数据
     */
    public function prepay()
    {
        $config = $this->config;
        $request = Request::instance();

        $openid = input('openid');
        $body = input('body');
        $order_sn =input('order_sn');
        $total_fee = input('total_fee');
        $attach = input('attach');


        //统一下单参数构造
        $unifiedorder = array(
            'attach' => $attach,
            'appid' => $config['appid'],
            'body' => $body,
            'mch_id' => $config['pay_mchid'],
            'nonce_str' => self::getNonceStr(),      //产生随机字符串，不长于32位
            'notify_url' => $config['notify_url'],
            'openid' => $openid,
            'out_trade_no' => $order_sn,      //     $order_sn . 'M' . time() . rand(0000, 9999)
            'spbill_create_ip' => $request->ip(),          //得到客户端的ip
            'total_fee' => $total_fee * 100,                   //单位为 分，所以需要 *100
            'trade_type' => 'JSAPI',
        );
//        print_r($unifiedorder);
        $unifiedorder['sign'] = self::makeSign($unifiedorder);   // 生成签名
        //请求数据
        $xmldata = self::array2xml($unifiedorder);                //将一个数组转换为 XML 结构的字符串
        $url = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
        $res = self::curl_post_ssl($url, $xmldata);                //微信支付发起请求
        if (!$res) {
            self::return_err("Can't connect the server");
        }
        $content = self::xml2array($res);                  //将xml转为array
        if (strval($content['result_code']) == 'FAIL') {
            self::return_err(strval($content['err_code_des']));
        }
        if (strval($content['return_code']) == 'FAIL') {
            self::return_err(strval($content['return_msg']));
        }
        self::return_data(array('data' => $content));
        //$this->ajaxReturn($content);
    }

    /**
     * 进行支付接口(POST)
     * @param string $prepay_id 预支付ID(调用prepay()方法之后的返回数据中获取)
     * @return  json的数据
     */
    public function pay()
    {
        $config = $this->config;
        $prepay_id = input('prepay_id');
        /*--------------------------------------------------------------------------------------------------------------------------------------------------*/
        //此处获得的 $prepay_id 建议保存到订单数据表中，可方便后期"服务通知"业务的使用
        /*--------------------------------------------------------------------------------------------------------------------------------------------------*/
        $data = array(
            'appId' => $config['appid'],
            'timeStamp' => time(),
            'nonceStr' => self::getNonceStr(),
            'package' => 'prepay_id=' . $prepay_id,
            'signType' => 'MD5'
        );
        $data['paySign'] = self::makeSign($data);

        self::return_data(array('details' => $data));
    }


    /**
     * 微信支付回调验证
     * @return array|bool
     */
    public function notify()
    {
        $xml = file_get_contents('php://input');
        //将服务器返回的XML数据转化为数组
        $aaaa = $data = self::xml2array($xml);
        // 保存微信服务器返回的签名sign
        $data_sign = $data['sign'];
        // sign不参与签名算法
        unset($data['sign']);
        $sign = self::makeSign($data);

        // 判断签名是否正确  判断支付状态
        if (($sign === $data_sign) && ($data['return_code'] == 'SUCCESS') && ($data['result_code'] == 'SUCCESS')) {
            $result = $data;
            //获取服务器返回的数据
            $out_trade_no = $data['out_trade_no'];            //订单单号
            $openid = $data['openid'];                                     //付款人openID
            $total_fee = $data['total_fee'];                             //付款金额
            $transaction_id = $data['transaction_id'];         //微信支付流水号

            //TODO 此时可以根据自己的业务逻辑 进行数据库更新操作----------------------------------------------------------------------------------------*/
            $this->payNotifyOrderDeal($data['attach'],$data['total_fee']);   //进行微信支付成功之后的处理，自行对数据库进行更新-----------------------------------------*/
        } else {
            $result = false;
        }
        // 返回状态给微信服务器
        if ($result) {
            $str = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        } else {
            $str = '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[签名失败]]></return_msg></xml>';
        }
        echo $str;
        return $str;
    }


    /**
     * 进行微信支付回调后的处理
     * TODO 此处的后续处理为本人的专属逻辑，请自行补充对应的业务逻辑即可
     * @param $result
     * TODO 强烈建议将其转化为 json 字符串形式，保存在数据表中，方便后期的微信退款操作
     * tip:$wx_pay_result_json = json_encode($result);
     */
    public function payNotifyOrderDeal($userid,$moneys)
    {
        //开启事务
        Db::startTrans();
        try {
            $money = $moneys/100;
            //进行微信充值成功之后的操作
            //在用户表中给用户加上余额
            $query = Db::name('user')->where('id',$userid)->setInc('money',$money);
            //添加一条流水记录 （userwater）
            $query1 = Db::name('userwater')->insert(['userid'=>$userid,'style'=>0,'status'=>1,'amount'=>$money,'adopt'=>1,'comments'=>'使用微信支付充值'.$money.'元','times'=>date('Y-m-d H:i:s')]);
            // 判断是否修改成功
            if (!$query || !$query1 ) {
                throw new \Exception("操作失败");
            }
            // 提交
            Db::commit();
//            return true;
            return $result = true;
        } catch (\Exception $e) {
            // 回滚
            Db::rollback();
//            return false;
            return $result = false;
        }
    }


    //---------------------------------------------------------------用到的函数------------------------------------------------------------

    /**
     * @param string $url get请求地址
     * @param int $httpCode 返回状态码
     * @param mixed
     */
    function curl_get($url,&$httpCode = 0){
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL,$url);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);

        //不做证书校验，部署在linux环境下请改位true
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
        curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);
        $file_contents = curl_exec($ch);
        $httpCode = curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $file_contents;
    }

    /**
     * 错误返回提示
     * @param string $errMsg 错误信息
     * @param string $status 错误码
     * @return  json的数据
     */
    protected function return_err($errMsg = 'error', $status = 0)
    {
        exit(json_encode(array('status' => $status, 'result' => 'fail', 'errmsg' => $errMsg)));
    }

    /**
     * 正确返回
     * @param    array $data 要返回的数组
     * @return  json的数据
     */
    protected function return_data($data = array())
    {
        exit(json_encode(array('status' => 1, 'result' => 'success', 'data' => $data)));
    }

    /**
     * 将一个数组转换为 XML 结构的字符串
     * @param array $arr 要转换的数组
     * @param int $level 节点层级, 1 为 Root.
     * @return string XML 结构的字符串
     */
    protected function array2xml($arr, $level = 1)
    {
        $s = $level == 1 ? "<xml>" : '';
        foreach ($arr as $tagname => $value) {
            if (is_numeric($tagname)) {
                $tagname = $value['TagName'];
                unset($value['TagName']);
            }
            if (!is_array($value)) {
                $s .= "<{$tagname}>" . (!is_numeric($value) ? '<![CDATA[' : '') . $value . (!is_numeric($value) ? ']]>' : '') . "</{$tagname}>";
            } else {
                $s .= "<{$tagname}>" . $this->array2xml($value, $level + 1) . "</{$tagname}>";
            }
        }
        $s = preg_replace("/([\x01-\x08\x0b-\x0c\x0e-\x1f])+/", ' ', $s);
        return $level == 1 ? $s . "</xml>" : $s;
    }

    /**
     * 将xml转为array
     * @param  string $xml xml字符串
     * @return array    转换得到的数组
     */
    protected function xml2array($xml)
    {
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $result = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $result;
    }

    /**
     * 产生随机字符串，不长于32位
     * @param int $length
     * @return 产生的随机字符串
     */
    protected function getNonceStr($length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

    /**
     * 生成签名
     * @return 签名
     */
    protected function makeSign($data)
    {
        //获取微信支付秘钥
        $key = $this->config['pay_apikey'];
        // 去空
        $data = array_filter($data);
        //签名步骤一：按字典序排序参数
        ksort($data);
        $string_a = http_build_query($data);
        $string_a = urldecode($string_a);
        //签名步骤二：在string后加入KEY
        //$config=$this->config;
        $string_sign_temp = $string_a . "&key=" . $key;
        //签名步骤三：MD5加密
        $sign = md5($string_sign_temp);
        // 签名步骤四：所有字符转为大写
        $result = strtoupper($sign);
        return $result;
    }

    /**
     * 微信支付发起请求
     */
    protected function curl_post_ssl($url, $xmldata, $second = 30, $aHeader = array())
    {
        $ch = curl_init();
        //超时时间
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //这里设置代理，如果有的话
        //curl_setopt($ch,CURLOPT_PROXY, '10.206.30.98');
        //curl_setopt($ch,CURLOPT_PROXYPORT, 8080);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);


        if (count($aHeader) >= 1) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $aHeader);
        }

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmldata);
        $data = curl_exec($ch);
        if ($data) {
            curl_close($ch);
            return $data;
        } else {
            $error = curl_errno($ch);
            echo "call faild, errorCode:$error\n";
            curl_close($ch);
            return false;
        }
    }
}