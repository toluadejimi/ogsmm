<?php

namespace App\Http\Controllers;

use App\Models\Fund;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\Gateway;
use App\Models\Category;
use App\Models\Language;
use App\Http\Traits\Notify;
use App\Http\Traits\Upload;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Helper\GoogleAuthenticator;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Stevebauman\Purify\Facades\Purify;
use Illuminate\Support\Facades\Redirect;
use DeviceDetector\Parser\Client\Browser;
use Illuminate\Support\Facades\Validator;

class HomeController extends Controller
{
    use Upload, Notify;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware(['auth']);

        $this->middleware(function ($request, $next) {
            $this->user = auth()->user();
            return $next($request);
        });
    }


    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {

        $data['walletBalance'] = $this->user->balance;
        $data['totalTrx'] = Transaction::where('user_id', $this->user->id)->count();
        $data['totalDeposit'] = Fund::where('user_id', $this->user->id)->where('status', 1)->sum('amount');
        $data['ticket'] = Ticket::where('user_id', $this->user->id)->count();

        $order['total'] = Order::where('user_id', $this->user->id)->count();
        $order['processing'] = Order::where('user_id', $this->user->id)->where('status', 'processing')->count();
        $order['pending'] = Order::where('user_id', $this->user->id)->where('status', 'pending')->count();
        $order['completed'] = Order::where('user_id', $this->user->id)->where('status', 'completed')->count();
        $data['transactions'] = Transaction::where('user_id', $this->user->id)->orderBy('id', 'DESC')->limit(5)->get();

        return view('user.pages.dashboard', $data, compact('order'));
    }


    public function transaction()
    {
        $transactions = $this->user->transaction()->orderBy('id', 'DESC')->paginate(config('basic.paginate'));
        return view('user.pages.transaction.index', compact('transactions'));
    }

    public function transactionSearch(Request $request)
    {
        $search = $request->all();
        $dateSearch = $request->datetrx;
        $date = preg_match("/^[0-9]{2,4}\-[0-9]{1,2}\-[0-9]{1,2}$/", $dateSearch);
        $transaction = Transaction::where('user_id', $this->user->id)->with('user')
            ->when(@$search['transaction_id'], function ($query) use ($search) {
                return $query->where('trx_id', 'LIKE', "%{$search['transaction_id']}%");
            })
            ->when(@$search['remark'], function ($query) use ($search) {
                return $query->where('remarks', 'LIKE', "%{$search['remark']}%");
            })
            ->when($date == 1, function ($query) use ($dateSearch) {
                return $query->whereDate("created_at", $dateSearch);
            })
            ->paginate(config('basic.paginate'));
        $transactions = $transaction->appends($search);


        return view('user.pages.transaction.index', compact('transactions'));

    }

    public function fundHistory()
    {
        $funds = Fund::where('user_id', $this->user->id)->where('status', '!=', 0)->orderBy('id', 'DESC')->with('gateway')->paginate(config('basic.paginate'));
        return view('user.pages.transaction.fundHistory', compact('funds'));
    }

    public function fundHistorySearch(Request $request)
    {
        $search = $request->all();

        $dateSearch = $request->date_time;
        $date = preg_match("/^[0-9]{2,4}\-[0-9]{1,2}\-[0-9]{1,2}$/", $dateSearch);

        $funds = Fund::orderBy('id', 'DESC')->where('user_id', $this->user->id)->where('status', '!=', 0)
            ->when(isset($search['name']), function ($query) use ($search) {
                return $query->where('transaction', 'LIKE', $search['name']);
            })
            ->when($date == 1, function ($query) use ($dateSearch) {
                return $query->whereDate("created_at", $dateSearch);
            })
            ->when(isset($search['status']), function ($query) use ($search) {
                return $query->where('status', $search['status']);
            })

            ->with('gateway')
            ->paginate(config('basic.paginate'));
        $funds->appends($search);

        return view('user.pages.transaction.fundHistory', compact('funds'));

    }


    

    public function addFund()
    {
        $transaction = Transaction::where('user_id', Auth::id())->paginate(10);
        return view('user.pages.addFund', compact('transaction'));
    }



    public function fund_now(Request $request)
    {

        $request->validate([
            'amount'      => 'required|numeric|gt:0',
        ]);

        Transaction::where('user_id', Auth::id())->where('status', 1)->delete() ?? null;

        if ($request->type == 1) {

            if ($request->amount < 100) {
                return back()->with('error', 'You can not fund less than NGN 100');
            }


            if ($request->amount > 100000) {
                return back()->with('error', 'You can not fund more than NGN 100,000');
            }




            $key = env('WEBKEY');
            $ref = "OGB" . random_int(000, 999) . date('ymdhis');
            $email = Auth::user()->email;

            $url = "https://web.enkpay.com/pay?amount=$request->amount&key=$key&ref=$ref&email=$email";


            $data                  = new Transaction();
            $data->user_id         = Auth::id();
            $data->amount          = $request->amount;
            $data->ref_id          = $ref;
            $data->type            = 2;
            $data->status          = 1; //initiate
            $data->save();


            return Redirect::to($url);
        }



        if ($request->type == 2) {

            if ($request->amount < 100) {
                return back()->with('error', 'You can not fund less than NGN 100');
            }


            if ($request->amount > 100000) {
                return back()->with('error', 'You can not fund more than NGN 100,000');
            }




            $ref = "VERFM" . random_int(000, 999) . date('ymdhis');
            $email = Auth::user()->email;


            $data                  = new Transaction();
            $data->user_id         = Auth::id();
            $data->amount          = $request->amount;
            $data->ref_id          = $ref;
            $data->type            = 2; //manual funding
            $data->status          = 1; //initiate
            $data->save();


            $message = Auth::user()->email . "| wants to fund Manually |  NGN " . number_format($request->amount) . " | with ref | $ref |  on SOCIAL BOOST PLUG";
            send_notification2($message);




            $data['account_details'] = AccountDetail::where('id', 1)->first();
            $data['amount'] = $request->amount;

            return view('user.pages.manual-fund', $data);

        }




    }





    public function apiKey()
    {
        $api_token = Auth::user()->api_token;
        return view('user.pages.apiKey', compact('api_token'));
    }

    public function search(Request $request)
    {
        $search = $request->all();
        $searchCat = Category::when(isset($search['category_title']), function ($query) use ($search) {
            return $query->where('category_title', 'LIKE', "%{$search['category_title']}%");
        })
            ->when(isset($search['category_status']), function ($query) use ($search) {
                return $query->where('category_title', $search['category_status']);
            })
            ->get();
        $searchCat->append($search);
        return view('admin.pages.services.show-category', compact('searchCat'));
    }


    public function profile()
    {
        $user = Auth::user();
        $languages =  Language::where('is_active',1)->orderBy('short_name')->get();
        return view('user.pages.profile.myprofile', compact('user','languages'));
    }


    public function updateProfile(Request $request)
    {
        $allowedExtensions = array('jpg', 'png', 'jpeg');

        $image = $request->image;
        $this->validate($request, [
            'image' => [
                'required',
                'max:4096',
                function ($fail) use ($image, $allowedExtensions) {
                    $ext = strtolower($image->getClientOriginalExtension());
                    if (($image->getSize() / 1000000) > 2) {
                        return $fail("Images MAX  2MB ALLOW!");
                    }
                    if (!in_array($ext, $allowedExtensions)) {
                        return $fail("Only png, jpg, jpeg images are allowed");
                    }
                }
            ]
        ]);
        $user = Auth::user();
        if ($request->hasFile('image')) {
            $path = config('location.user.path');
            try {
                $user->image = $this->uploadImage($image, $path);
            } catch (\Exception $exp) {
                return back()->with('error', 'Could not upload your ' . $image)->withInput();
            }
        }
        $user->save();
        return back()->with('success', 'Updated Successfully.');
    }


    public function updateInformation(Request $request)
    {
        $req = Purify::clean($request->all());
        $user = Auth::user();

        $rules = [
            'firstname' => 'required',
            'lastname' => 'required',
            'username' => "sometimes|required|alpha_dash|min:5|unique:users,username," . $user->id,
            'address' => 'required',
            'language_id' => 'required|sometimes',
        ];
        $message = [
            'firstname.required' => 'First Name field is required',
            'lastname.required' => 'Last Name field is required',
        ];

        $validator = Validator::make($req, $rules, $message);
        if ($validator->fails()) {
            $validator->errors()->add('profile', '1');
            return back()->withErrors($validator)->withInput();
        }
        $user->firstname = $req['firstname'];
        $user->lastname = $req['lastname'];
        $user->username = $req['username'];
        $user->address = $req['address'];

        if(isset($req['language_id'])){
            $user->language_id = $req['language_id'];
        }
        $user->save();

        return back()->with('success', 'Updated Successfully.');
    }


    public function updatePassword(Request $request)
    {

        $rules = [
            'current_password' => "required",
            'password' => "required|min:5|confirmed",
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $validator->errors()->add('password', '1');
            return back()->withErrors($validator)->withInput();
        }
        $user = Auth::user();
        try {
            if (Hash::check($request->current_password, $user->password)) {
                $user->password = bcrypt($request->password);
                $user->save();
                return back()->with('success', 'Password Changes successfully.');
            } else {
                throw new \Exception('Current password did not match');
            }
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }


    public function referral()
    {
        $title = "My Referral";
        $referrals = getLevelUser($this->user->id);
        return view('user.pages.referral', compact('title', 'referrals'));
    }

    public function referralBonus()
    {
        $title = "Referral Bonus";
        $transactions = $this->user->referralBonusLog()->latest()->with('bonusBy:id,firstname,lastname')->paginate(config('basic.paginate'));
        return view('user.pages.transaction.referral-bonus', compact('title', 'transactions'));
    }

    public function referralBonusSearch(Request $request)
    {
        $title = "Referral Bonus";
        $search = $request->all();
        $dateSearch = $request->datetrx;
        $date = preg_match("/^[0-9]{2,4}\-[0-9]{1,2}\-[0-9]{1,2}$/", $dateSearch);

        $transaction = $this->user->referralBonusLog()->latest()
            ->with('bonusBy:id,firstname,lastname')
            ->when(isset($search['search_user']), function ($query) use ($search) {
                return $query->whereHas('bonusBy', function ($q) use ($search) {
                    $q->where(DB::raw('concat(firstname, " ", lastname)'), 'LIKE', "%{$search['search_user']}%")
                        ->orWhere('firstname', 'LIKE', '%' . $search['search_user'] . '%')
                        ->orWhere('lastname', 'LIKE', '%' . $search['search_user'] . '%')
                        ->orWhere('username', 'LIKE', '%' . $search['search_user'] . '%');
                });
            })
            ->when($date == 1, function ($query) use ($dateSearch) {
                return $query->whereDate("created_at", $dateSearch);
            })
            ->paginate(config('basic.paginate'));
        $transactions = $transaction->appends($search);

        return view('user.pages.transaction.referral-bonus', compact('title', 'transactions'));
    }

    public function twoStepSecurity()
    {
        $basic = (object)config('basic');
        $ga = new GoogleAuthenticator();
        $secret = $ga->createSecret();
        $qrCodeUrl = $ga->getQRCodeGoogleUrl($this->user->username . '@' . $basic->site_title, $secret);
        $previousCode = $this->user->two_fa_code;

        $previousQR = $ga->getQRCodeGoogleUrl($this->user->username . '@' . $basic->site_title, $previousCode);
        return view('user.twoFA.index', compact('secret', 'qrCodeUrl', 'previousCode', 'previousQR'));
    }

    public function twoStepEnable(Request $request)
    {
        $user = $this->user;
        $this->validate($request, [
            'key' => 'required',
            'code' => 'required',
        ]);
        $ga = new GoogleAuthenticator();
        $secret = $request->key;
        $oneCode = $ga->getCode($secret);

        $userCode = $request->code;
        if ($oneCode == $userCode) {
            $user['two_fa'] = 1;
            $user['two_fa_verify'] = 1;
            $user['two_fa_code'] = $request->key;
            $user->save();
            $this->mail($user, 'TWO_STEP_ENABLED', [
                'action' => 'Enabled',
                'code' => $user->two_fa_code,
                'ip' => request()->ip(),
                'time' => date('d M, Y h:i:s A'),
            ]);
            return back()->with('success', 'Two Factor has been enabled.');
        } else {
            return back()->with('error', 'Wrong Verification Code.');
        }

    }

    public function twoStepDisable(Request $request)
    {
        $this->validate($request, [
            'code' => 'required',
        ]);
        $user = $this->user;
        $ga = new GoogleAuthenticator();

        $secret = $user->two_fa_code;
        $oneCode = $ga->getCode($secret);
        $userCode = $request->code;

        if ($oneCode == $userCode) {
            $user['two_fa'] = 0;
            $user['two_fa_verify'] = 1;
            $user['two_fa_code'] = null;
            $user->save();
            $this->mail($user, 'TWO_STEP_DISABLED', [
                'action' => 'Disabled',
                'ip' => request()->ip(),
                'time' => date('d M, Y h:i:s A'),
            ]);

            return back()->with('success', 'Two Factor has been disabled.');
        } else {
            return back()->with('error', 'Wrong Verification Code.');
        }
    }



}
