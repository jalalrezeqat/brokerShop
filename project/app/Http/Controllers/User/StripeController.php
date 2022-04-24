<?php

namespace App\Http\Controllers\User;

use App\Classes\BshopMailer;
use App\Models\Generalsetting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Slider;
use App\Models\Subscription_slider;

use Auth;
use Carbon\Carbon;
use Cartalyst\Stripe\Laravel\Facades\Stripe;
use Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Redirect;
use Stripe\Error\Card;
use URL;
use Validator;
use Illuminate\Support\Str;

use App\Http\Controllers\Controller;

class StripeController extends Controller
{

    public function __construct()
    {
        //Set Spripe Keys
        $stripe = Generalsetting::findOrFail(1);
        Config::set('services.stripe.key', $stripe->stripe_key);
        Config::set('services.stripe.secret', $stripe->stripe_secret);
    }


    public function store(Request $request){
        $this->validate($request, [
            'shop_name'   => 'unique:users',
           ],[ 
               'shop_name.unique' => 'This shop name has already been taken.'
            ]);
        $user = Auth::user();
        $package = $user->subscribes()->where('status',1)->orderBy('id','desc')->first();
        $subs = Subscription::findOrFail($request->subs_id);
        $settings = Generalsetting::findOrFail(1);
        $success_url = action('User\UserController@index');
        $item_name = $subs->title." Plan";
        $item_number = Str::random(10);
        $item_amount = $subs->price;
        $item_currency = $subs->currency_code;
        $validator = Validator::make($request->all(),[
                        'card' => 'required',
                        'cvv' => 'required',
                        'month' => 'required',
                        'year' => 'required',
                    ]);
        if ($validator->passes()) {

            $stripe = Stripe::make(Config::get('services.stripe.secret'));
            try{
                $token = $stripe->tokens()->create([
                    'card' =>[
                            'number' => $request->card,
                            'exp_month' => $request->month,
                            'exp_year' => $request->year,
                            'cvc' => $request->cvv,
                        ],
                    ]);
                if (!isset($token['id'])) {
                    return back()->with('error','Token Problem With Your Token.');
                }

                $charge = $stripe->charges()->create([
                    'card' => $token['id'],
                    'currency' => $item_currency,
                    'amount' => $item_amount,
                    'description' => $item_name,
                    ]);

                if ($charge['status'] == 'succeeded') {

                    $today = Carbon::now()->format('Y-m-d');
                    $date = date('Y-m-d', strtotime($today.' + '.$subs->days.' days'));
                    $input = $request->all();  
                    $user->is_vendor = 2;
                    if(!empty($package))
                    {
                        if($package->subscription_id == $request->subs_id)
                        {
                            $newday = strtotime($today);
                            $lastday = strtotime($user->date);
                            $secs = $lastday-$newday;
                            $days = $secs / 86400;
                            $total = $days+$subs->days;
                            $user->date = date('Y-m-d', strtotime($today.' + '.$total.' days'));
                        }
                        else
                        {
                            $user->date = date('Y-m-d', strtotime($today.' + '.$subs->days.' days'));
                        }
                    }
                    else
                    {
                        $user->date = date('Y-m-d', strtotime($today.' + '.$subs->days.' days'));
                    }
                    $user->mail_sent = 1;     
                    $user->update($input);
                    $sub = new UserSubscription;
                    $sub->user_id = $user->id;
                    $sub->subscription_id = $subs->id;
                    $sub->title = $subs->title;
                    $sub->currency = $subs->currency;
                    $sub->currency_code = $subs->currency_code;
                    $sub->price = $subs->price;
                    $sub->days = $subs->days;
                    $sub->allowed_products = $subs->allowed_products;
                    $sub->details = $subs->details;
                    $sub->method = 'Stripe';
                    $sub->txnid = $charge['balance_transaction'];
                    $sub->charge_id = $charge['id'];
                    $sub->status = 1;
                    $sub->save();
                    if($settings->is_smtp == 1)
                    {
                    $data = [
                        'to' => $user->email,
                        'type' => "vendor_accept",
                        'cname' => $user->name,
                        'oamount' => "",
                        'aname' => "",
                        'aemail' => "",
                        'onumber' => "",
                    ];
                    $mailer = new BshopMailer();
                    $mailer->sendAutoMail($data);        
                    }
                    else
                    {
                    $headers = "From: ".$settings->from_name."<".$settings->from_email.">";
                    mail($user->email,'Your Vendor Account Activated','Your Vendor Account Activated Successfully. Please Login to your account and build your own shop.',$headers);
                    }

                    return redirect()->route('user-dashboard')->with('success','Vendor Account Activated Successfully');

                }
                
            }catch (Exception $e){
                return back()->with('unsuccess', $e->getMessage());
            }catch (\Cartalyst\Stripe\Exception\CardErrorException $e){
                return back()->with('unsuccess', $e->getMessage());
            }catch (\Cartalyst\Stripe\Exception\MissingParameterException $e){
                return back()->with('unsuccess', $e->getMessage());
            }
        }
        return back()->with('unsuccess', 'Please Enter Valid Credit Card Informations.');
    }

// 
// /////
public function storeslid(Request $request){
   $subs=session::get('vendor-slider');
    $user = Auth::user();
    $subss = Subscription_slider::findOrFail($request->subs_id);
    $settings = Generalsetting::findOrFail(1);
    $success_url = action('User\UserController@index');
    $item_name = $subss->title." Plan";
    $item_number = Str::random(10);
    $item_amount = $subss->price;
    $item_currency = $subss->currency_code;
    $validator = Validator::make($request->all(),[
                    'card' => 'required',
                    'cvv' => 'required',
                    'month' => 'required',
                    'year' => 'required',
                ]);
    if ($validator->passes()) {

        $stripe = Stripe::make(Config::get('services.stripe.secret'));
        try{
            $token = $stripe->tokens()->create([
                'card' =>[
                        'number' => $request->card,
                        'exp_month' => $request->month,
                        'exp_year' => $request->year,
                        'cvc' => $request->cvv,
                    ],
                ]);
            if (!isset($token['id'])) {
                return back()->with('error','Token Problem With Your Token.');
            }

            $charge = $stripe->charges()->create([
                'card' => $token['id'],
                'currency' => $item_currency,
                'amount' => $item_amount,
                'description' => $item_name,
                ]);

            if ($charge['status'] == 'succeeded') {

                $today = Carbon::now()->format('Y-m-d');
                $date = date('Y-m-d', strtotime($today.' + '.$subss->days.' days'));
                $input = $request->all();  
                $user->is_vendor = 2;
                // if(!empty($package))
                // {
                //     if($package->subscription_id == $request->subs_id)
                //     {
                //         $newday = strtotime($today);
                //         $lastday = strtotime($user->date);
                //         $secs = $lastday-$newday;
                //         $days = $secs / 86400;
                //         $total = $days+$subs->days;
                //         $user->date = date('Y-m-d', strtotime($today.' + '.$total.' days'));
                //     }
                //     else
                //     {
                //         $user->date = date('Y-m-d', strtotime($today.' + '.$subs->days.' days'));
                //     }
                // }
                // else
                // {
                //     $user->date = date('Y-m-d', strtotime($today.' + '.$subs->days.' days'));
                // }
               
                $user->update($input);
                $sub = new Slider;
                // $sub->id = $subs->id;
                $sub->subtitle_text = $subs->subtitle_text;
                $sub->subtitle_size = $subs->subtitle_size;
                $sub->subtitle_color = $subs->subtitle_color	;
                $sub->subtitle_anime = $subs->subtitle_anime	;
                $sub->title_text = $subs->title_text;
                $sub->title_size = $subs->title_size;
                $sub->title_color = $subs->title_color	;
                $sub->title_anime = $subs->title_anime	;
                $sub->details_text = $subs->details_text;
                $sub->details_size = $subs->details_size;
                $sub->details_color = $subs->details_color	;
                $sub->details_anime = $subs->details_anime	;
                $sub->photo =$subs->photo;
                $sub->position =$subs->position;
                $sub->link =$subs->link;
                // $sub->method = 'Stripe';
                // $sub->txnid = $charge['balance_transaction'];
                // $sub->charge_id = $charge['id'];
                // $sub->status = 1;
                $sub->save();
                if($settings->is_smtp == 1)
                {
                $data = [
                    'to' => $user->email,
                    'type' => "vendor_accept",
                    'cname' => $user->name,
                    'oamount' => "",
                    'aname' => "",
                    'aemail' => "",
                    'onumber' => "",
                ];
                $mailer = new BshopMailer();
                $mailer->sendAutoMail($data);        
                }
                else
                {
                $headers = "From: ".$settings->from_name."<".$settings->from_email.">";
                mail($user->email,'Your Vendor Account Activated','Your Vendor Account Activated Successfully. Please Login to your account and build your own shop.',$headers);
                }

                return redirect()->route('user-dashboard')->with('success','Vendor Account Activated Successfully');

            }
            
        }catch (Exception $e){
            return back()->with('unsuccess', $e->getMessage());
        }catch (\Cartalyst\Stripe\Exception\CardErrorException $e){
            return back()->with('unsuccess', $e->getMessage());
        }catch (\Cartalyst\Stripe\Exception\MissingParameterException $e){
            return back()->with('unsuccess', $e->getMessage());
        }
    }
    return back()->with('unsuccess', 'Please Enter Valid Credit Card Informations.');
}

}
