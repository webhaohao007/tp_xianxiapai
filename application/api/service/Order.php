<?php
/**
 * Created by PhpStorm.
 * User: hao
 * Date: 2020/1/1
 * Time: 15:12
 */

namespace app\api\service;


use app\api\model\OrderProduct;
use app\api\model\Product;
use app\api\model\UserActivity;
use app\api\model\UserAddress;
use app\lib\exception\OrderException;
use app\lib\exception\UserException;
use think\Db;

class Order
{
    //  订单的商品列表，也就是客户端传递过来的products 参数
    protected $oProducts;

    // 真实的商品信息 （包括库存量）
    protected $products;

    protected $uid;

    public function place($uid, $oProducts)
    {
        // oProducts 和 products 作对比
        // products 从数据库中查询出来
        $this->oProducts = $oProducts;
        $this->products = $this->getProductsByOrder($oProducts);
        $this->uid = $uid;
        $status = $this -> getOrderStatus();
        if($status['pass']){
            $status['order_id'] = -1;
            return $status;
        }

        // 开始创建订单

        $orderSnap = $this -> snapOrder($status);

        $order = $this ->createOrder($orderSnap);
        $order['pass'] = true;
        return $order;

    }
    private function createOrder($snap){
        Db::startTrans();
        try{
            $orderNo = $this->makeOrderNo();
            $order = new \app\api\model\Order();
            $order ->order_no = $orderNo;
            $order->user_id = $this->uid;
            $order->order_no = $orderNo;
            $order->total_price = $snap['orderPrice'];
            $order->total_count = $snap['totalCount'];
            $order->snap_img = $snap['snapImg'];
            $order->snap_name = $snap['snapName'];
            $order->snap_address = $snap['snapAddress'];
            $order->snap_items = json_encode($snap['pStatus']);

            $order->save();

            $orderID = $order->id;

            $create_time = $order->create_time;

            foreach($this->oProducts as &$p){
                $p['order_id'] = $orderID;
            }
            $orderProduct = new OrderProduct();
            $orderProduct->saveAll($this->oProducts);
            Db::commit();
            return [
                'order_no' => $orderNo,
                'order_id' => $orderID,
                'create_time'=>$create_time
            ];
        }
        catch(Exception $ex){
            Db::rollback();
            throw $ex;
        }

    }
    public static function makeOrderNo(){
        $yCode = array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J');
        $orderSn =
            $yCode[intval(date('Y')) - 2020] . strtoupper(dechex(date('m'))) . date(
                'd') . substr(time(), -5) . substr(microtime(), 2, 5) . sprintf(
                '%02d', rand(0, 99));
        return $orderSn;
    }
    // 生成订单快照
    private function snapOrder($status){
        $snap = [
            'orderPrice'=>0,
            'totalCount'=>0,
            'pStatus' => [],
            'snapAddress'=>null,
            'snapName' =>'',
            'snapImg' => ''
        ];

        $snap['orderPrice'] = $status['orderPrice'];
        $snap['totalCount'] = $status['totalCount'];
        $snap['pStatus'] = $status['pStatusArray'];
        $snap['snapAddress'] = json_encode($this->getUserAddress());
        $snap['snapName'] = $this->products[0]['name'];
        $snap['snapImg'] =  $this->products[0]['main_img_url'];

        if(count($this->products)>1){
            $snap['snapName'].='等';
        }
    }
    private function getUserAddress(){
        $userAddress = UserAddress::where('user_id','=',$this->uid)
                       ->find();
        if(!$userAddress){
            throw new UserException([
                'msg' =>'用户收货地址不存在，下单失败',
                'errorCode'=>60001
            ]);
        }
        return $userAddress->toArray();
    }
    private function getOrderStatus()
    {
        $status = [
            'pass' => true,
            'orderPrice' => 0,
            'totalCount' => 0,
            'pStatusArray' => []
        ];
        foreach ($this->oProducts as $oProduct) {
            $pStatus = $this->getProductStatus(
                $oProduct['product_id'],
                $oProduct['count'],
                $this->products
            );
            if (!$pStatus['haveStock']) {
                $status['pass'] = false;
            }
            $status['orderPrice'] += $pStatus['totalPrice'];
            $status['totalCount'] += $pStatus['count'];
            array_push($status['pStatusArray'], $pStatus);
        }
        return $status;
    }

    private function getProductStatus($oPID, $oCount, $products)
    {
        $pIndex = -1;
        $pStatus = [
            'id' => null,
            'haveStock' => false,
            'count' => 0,
            'totalPrice' => 0
        ];
        for ($i = 0; $i < count($products); $i++) {
            if ($oPID == $products[$i]['id']) {
                $pIndex = $i;
            }
        }
        if ($pIndex == -1) {
            // 客户端传递的product_id 有可能根本不存在
            throw new OrderException(
                ['msg' => 'id为' . $oPID . '商品不存在，创建订单失败']
            );
        } else {
            $product = $products[$pIndex];
            $pStatus['id'] = $product['id'];
            $pStatus['name'] = $product['name'];
            $pStatus['count'] = $oCount;
            $pStatus['totalPrice'] = $product['price'] * $oCount;
            if ($product['stock'] - $oCount >= 0) {
                $pStatus['haveStock'] = true;
            }
        }
        return $pStatus;
    }

    private function getProductsByOrder($oProducts)
    {
        foreach ($oProducts as $item) {
            array_push($oPIDs, $item['product_id']);
        }
        $products = Product::all($oPIDs)
            ->visible(['id', 'price', 'stock', 'name', 'main_img_url'])
            ->toArray();
        return $products;

    }
}