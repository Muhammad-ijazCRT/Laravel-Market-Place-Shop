<?php

namespace App\Http\Controllers\Payment\Checkout;

use App\{
    Models\Cart,
    Models\Order,
    Classes\GeniusMailer
};
use Session;
use OrderHelper;
use App\Models\State;
use App\Models\Reward;
use App\Models\Country;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CashOnDeliveryController extends CheckoutBaseControlller
{
    public function store(Request $request)
    {
        $input = $request->all();
        if($request->pass_check) {
            $auth = OrderHelper::auth_check($input); // For Authentication Checking
            if(!$auth['auth_success']){
                return redirect()->back()->with('unsuccess',$auth['error_message']);
            }
        }

// dd(Session::has('cart'));
// $cardData = Session::get('cart');
// return $cardData;
// dd($cardData);

$cardData = Session::get('cart');
        if (!Session::has('cart')) {
            return redirect()->route('front.cart')->with('success',__("You don't have any product to checkout."));
        }

        $oldCart = Session::get('cart');
        $cart = new Cart($oldCart);
        $data = OrderHelper::license_check($cart); // For License Checking
        $t_oldCart = Session::get('cart');
        $t_cart = new Cart($t_oldCart);
        $new_cart = [];
        $new_cart['totalQty'] = $t_cart->totalQty;
        $new_cart['totalPrice'] = $t_cart->totalPrice;
        $new_cart['items'] = $t_cart->items;
        $new_cart = json_encode($new_cart);
        $temp_affilate_users = OrderHelper::product_affilate_check($cart); // For Product Based Affilate Checking
        $affilate_users = $temp_affilate_users == null ? null : json_encode($temp_affilate_users);
        
        // dd($temp_affilate_users);
        $order = new Order;
        $success_url = route('front.payment.return');
        $input['user_id'] = Auth::check() ? Auth::user()->id : NULL;
        $input['cart'] = $new_cart;
        $input['affilate_users'] = $affilate_users;
        $input['pay_amount'] = $request->total / $this->curr->value;
        $input['order_number'] = Str::random(4).time();
        $input['wallet_price'] = $request->wallet_price / $this->curr->value;
        

        if($input['tax_type'] == 'state_tax'){
            $input['tax_location'] = State::findOrFail($input['tax'])->state;
        }else{
            $input['tax_location'] = Country::findOrFail($input['tax'])->country_name;
        }
        $input['tax'] = Session::get('current_tax');
        // dd($input['user_id']);

        if (Session::has('affilate')) {
            $val = $request->total / $this->curr->value;
            $val = $val / 100;
            $sub = $val * $this->gs->affilate_charge;
            if($temp_affilate_users != null){
                $t_sub = 0;
                foreach($temp_affilate_users as $t_cost){
                    $t_sub += $t_cost['charge'];
                }
                $sub = $sub - $t_sub;
            }
            if($sub > 0){
                $user = OrderHelper::affilate_check(Session::get('affilate'),$sub,$input['dp']); // For Affiliate Checking
                $input['affilate_user'] = Session::get('affilate');
                $input['affilate_charge'] = $sub;
            }

        }

        
        // order save and complete
        $order->fill($input)->save();
        $order->tracks()->create(['title' => 'Pending', 'text' => 'You have successfully placed your order.' ]);
        $order->notifications()->create();

        if($input['coupon_id'] != "") {
            OrderHelper::coupon_check($input['coupon_id']); // For Coupon Checking
        }

        if(Auth::check()){
            if($this->gs->is_reward == 1){
                $num = $order->pay_amount;
                $rewards = Reward::get();
                foreach ($rewards as $i) {
                    $smallest[$i->order_amount] = abs($i->order_amount - $num);
                }
                
                asort($smallest);
                $final_reword = Reward::where('order_amount',key($smallest))->first();
                Auth::user()->update(['reward' => (Auth::user()->reward + $final_reword->reward)]);
            }
        }
        
        OrderHelper::size_qty_check($cart); // For Size Quantiy Checking
        $data = OrderHelper::stock_check($cart); // For Stock Checking
        OrderHelper::vendor_order_check($cart,$order); // For Vendor Order Checking
        

        Session::put('temporder',$order);
        Session::put('tempcart',$cart);
        Session::forget('cart');
        Session::forget('already');
        Session::forget('coupon');
        Session::forget('coupon_total');
        Session::forget('coupon_total1');
        Session::forget('coupon_percentage');
        
        

        // $order->wallet_price = 100; for kycard only
        if ($order->user_id != 0 && $order->wallet_price != 0) {
            $data = OrderHelper::add_to_transaction($order,$order->wallet_price); // Store To Transactions
        }

        //Sending Email To Buyer
        $data = [
            'to' => $order->customer_email,
            'type' => "new_order",
            'cname' => $order->customer_name,
            'oamount' => "",
            'aname' => "",
            'aemail' => "",
            'wtitle' => "",
            'onumber' => $order->order_number,
        ];

        // $mailer = new GeniusMailer();
        // $mailer->sendAutoOrderMail($data,$order->id);

        //Sending Email To Admin
        // $data = [
        //     'to' => $this->ps->contact_email,
        //     'subject' => "New Order Recieved!!",
        //     'body' => "Hello Admin!<br>Your store has received a new order.<br>Order Number is ".$order->order_number.".Please login to your panel to check. <br>Thank you.",
        // ];
        // $mailer = new GeniusMailer();
        // $mailer->sendCustomMail($data);

        // dd('adfasdfas');

        return redirect($success_url);
    }
}
