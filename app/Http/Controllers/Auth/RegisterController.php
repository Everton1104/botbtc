<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\WhatsappController;
use App\Models\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
            'name'                 => ['required', 'string', 'max:255'],
            'whatsapp'             => ['required', 'string', 'max:20'],
            'email'                => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password'             => ['required', 'string', 'min:8', 'confirmed'],
            'g-recaptcha-response' => [
                'required',
                function ($attribute, $value, $fail) {
                    $secret = env('RECAPTCHA_SECRET_KEY');

                    if (!$secret) return; // chave não configurada (dev local)

                    $res = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                        'secret'   => $secret,
                        'response' => $value,
                    ])->json();

                    if (!($res['success'] ?? false)) {
                        $fail('Confirme que você não é um robô.');
                    }
                },
            ],
        ], [
            'g-recaptcha-response.required' => 'Confirme que você não é um robô.',
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @return User
     */
    protected function create(array $data)
    {
        $numero = preg_replace('/\D/', '', $data['whatsapp']);

        // Adiciona código do Brasil (55) se o usuário não informou
        if (strlen($numero) <= 11) {
            $numero = '55' . $numero;
        }

        return User::create([
            'name'     => $data['name'],
            'whatsapp' => $numero,
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }

    protected function registered(Request $request, User $user)
    {
        WhatsappController::enviarCodigoVerificacao($user);

        return redirect()->route('verificar.whatsapp')
            ->with('status', 'Enviamos um código de 6 dígitos para o seu WhatsApp. Digite-o abaixo para ativar sua conta.');
    }
}
