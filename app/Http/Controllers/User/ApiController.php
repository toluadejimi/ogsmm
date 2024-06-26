<?php

namespace App\Http\Controllers\User;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ApiController extends Controller
{
    public function index()
    {
        $data['api_token'] = Auth::user()->api_token;
        if (Auth::check()) {
            return view('user.pages.api.index', $data);
        }
        return redirect()->route('apiDocs');
    }

    public function apiGenerate()
    {
        $user = Auth::user();
        $user->api_token = Str::random(20);
        $user->save();
        return $user->api_token;
    }


    public function e_fund(request $request)
    {

        $get_user = User::where('email', $request->email)->first() ?? null;

        if ($get_user == null) {

            return response()->json([
                'status' => false,
                'message' => 'No one user found, please check email and try again',
            ]);
        }


        User::where('email', $request->email)->increment('balance', $request->amount) ?? null;


        $amount = number_format($request->amount, 2);

        Transaction::where('ref_id', $request->order_id)->update(['status' => 2]);


        return response()->json([
            'status' => true,
            'message' => "NGN $amount has been successfully added to your wallet",
        ]);


    }
}
