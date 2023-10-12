<?php

namespace App\Http\Controllers\Payment\Checkout;

use App\{
    Models\Cart,
    Models\Order,
    Classes\GeniusMailer
};
use Session;
use Exception;
use OrderHelper;
use Stripe\Token;
use Stripe\Stripe;
use App\Models\User;
use App\Models\State;
use App\Models\Reward;
use App\Models\Country;
use Illuminate\Support\Str;
use Illuminate\Http\Request;


use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Http;

class ManualPaymentController extends CheckoutBaseControlller
{
    public function store(Request $request)
    {
        // $request->validate([
        //     'title' => 'required|unique:posts|max:255',
        //     'body' => 'required',
        // ]);

        if(!$request->mlm_email || !$request->mlm_password)
        {
            
            return redirect()->back()->with('unsuccess','Please enter email and password!');
        }
        
        $data = [
            "email"=> $request->mlm_email,
            "password"=> $request->mlm_password
        ];
        // $response = Http::post('http://127.0.0.1:8001/api/kycard-login', $data);
        $response = Http::post('https://staging.kosmomoney.com/api/kycard-login', $data);
        
        if(isset($response['user']))
        {
            // return  $response['user'];
        }else{
            return redirect()->back()->with('unsuccess','Please enter valid credentials!');
        }


        // dd('asfdasd');
        

        $mlm_wallet = isset($response['user']['wallet']) ? (int)$response['user']['wallet'] : 0;
        $mlm_balance = isset($response['user']['balance']) ? (int)$response['user']['balance'] : 0;

        $mlm_total = $mlm_wallet + $mlm_balance;
        if($mlm_total < $request->total)
        {
            return redirect()->back()->with('unsuccess','you have insufficient amount!');
            // return redirect()->back();
        }

        $request->total =200;
        // if ($mlm_wallet > $request->total) {
        //     $mlm_wallet = $mlm_wallet - $request->total;
        // } elseif ($mlm_wallet < $request->total && $mlm_total > $request->total) {
        //     $need_from_balance = $request->total - $mlm_wallet;
        //     $mlm_balance -= $need_from_balance;
        //     $mlm_wallet = 0;
        // }else{
        //     return redirect()->back()->with('unsuccess','you have insufficient amount!');
        //     // return redirect()->back();
        // }
        

        // dd($request->total);
        // dd($mlm_balance, $mlm_wallet);




        

        $input = $request->all();
        $rules = ['txnid' => 'required'];

        // $messages = ['required' => __('The Transaction ID field is required.')];
        // \Validator::make($input, $rules, $messages);
        
        if($request->pass_check) {
            $auth = OrderHelper::auth_check($input); // For Authentication Checking
            if(!$auth['auth_success']){
                return redirect()->back()->with('unsuccess',$auth['error_message']);
            }
        }

        if (!Session::has('cart')) {
            return redirect()->route('front.cart')->with('success',__("You don't have any product to checkout."));
        }

        $oldCart = Session::get('cart');
        // dd($oldCart);
        $cart = new Cart($oldCart);
        
        OrderHelper::license_check($cart); // For License Checking
        $t_oldCart = Session::get('cart');
        $t_cart = new Cart($t_oldCart);
        $new_cart = [];
        $new_cart['totalQty'] = $t_cart->totalQty;
        $new_cart['totalPrice'] = $t_cart->totalPrice;
        $new_cart['items'] = $t_cart->items;
        $new_cart = json_encode($new_cart);
        $temp_affilate_users = OrderHelper::product_affilate_check($cart); // For Product Based Affilate Checking
        $affilate_users = $temp_affilate_users == null ? null : json_encode($temp_affilate_users);

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

        $data = $order->fill($input)->save();
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
        OrderHelper::stock_check($cart); // For Stock Checking
        OrderHelper::vendor_order_check($cart,$order); // For Vendor Order Checking
        
        Session::put('temporder',$order);
        Session::put('tempcart',$cart);
        Session::forget('cart');
        Session::forget('already');
        Session::forget('coupon');
        Session::forget('coupon_total');
        Session::forget('coupon_total1');
        Session::forget('coupon_percentage');



        if ($order->user_id != 0 && $order->wallet_price != 0) {
            OrderHelper::add_to_transaction($order,$order->wallet_price); // Store To Transactions
        }




        // adding price to seller account
        $sellerIds = $request->input('seller_id', []); 
        $productPrices = $request->input('product_price', []); 
    
        // Validate that the number of seller IDs and product prices match
        if (count($sellerIds) !== count($productPrices)) {
            return response()->json(['error' => 'Invalid data provided'], 400);
        }
        // Combine seller IDs and product prices into an associative array
        $sellerProductPrices = array_combine($sellerIds, $productPrices);
        // Retrieve sellers based on seller IDs
        $sellers = User::whereIn('id', $sellerIds)->get();
        // Associate sellers with product prices
        foreach ($sellers as $seller) {
            $sellerId = $seller->id;
            
            // Check if the seller is associated with a product price
            if (isset($sellerProductPrices[$sellerId])) {
                $productPrice = $sellerProductPrices[$sellerId];
    
                // Update the product price for the seller in the User table
                $seller->kmoney = $productPrice;
                $seller->save();
            }
        }

        
        $data = [
            "email"=> $request->mlm_email,
            "password"=> $request->mlm_password,
            "product_title"=> 'product sadfa',
            'product_price' => $request->total
        ];


        $response = Http::post('https://staging.kosmomoney.com/api/update-user-amount', $data);
        // $response = Http::post('http://localhost:8001/api/update-user-amount', $data);
            return $response;
        


        //Sending Email To Buyer
        // $data = [
        //     'to' => $order->customer_email,
        //     'type' => "new_order",
        //     'cname' => $order->customer_name,
        //     'oamount' => "",
        //     'aname' => "",
        //     'aemail' => "",
        //     'wtitle' => "",
        //     'onumber' => $order->order_number,
        // ];

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
        

        return redirect($success_url)->with('success',"Thanks for purchasing the product.");
    }
}
