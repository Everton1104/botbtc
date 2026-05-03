<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\BotInvestment;
use App\Http\Controllers\BinanceController;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @return User
     */
    protected function create(array $data)
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // pegar patrimônio atual do bot principal
        $binance = new BinanceController();
        $saldos = $binance->getSaldos();
        $preco  = $binance->getPrecoBTC();

        $brl = collect($saldos['balances'])->firstWhere('asset', 'BRL');
        $brl_total = (float) $brl['free'] + (float) $brl['locked'];

        $btc = collect($saldos['balances'])->firstWhere('asset', 'BTC');
        $btc_total = (float) $btc['free'] + (float) $btc['locked'];

        $patrimonioAtualBot = $brl_total + ($btc_total * $preco);

        // criar registro do investimento do usuário
        BotInvestment::create([
            'user_id' => $user->id,
            'investimento_inicial' => 0,
            'patrimonio_inicial' => $patrimonioAtualBot,
            'lucro_atual' => 0,
        ]);

        return $user;
    }
}
