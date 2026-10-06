<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Notifications\ResetPassword as ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'whatsapp', 'whatsapp_code', 'whatsapp_code_expires_at', 'whatsapp_verified_at', 'chave_pix', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'          => 'datetime',
            'whatsapp_verified_at'       => 'datetime',
            'whatsapp_code_expires_at'   => 'datetime',
            'password'                   => 'hashed',
        ];
    }

    public function whatsappVerificado(): bool
    {
        return $this->whatsapp_verified_at !== null;
    }

    public function codigoValido(string $codigo): bool
    {
        return $this->whatsapp_code === $codigo
            && $this->whatsapp_code_expires_at
            && $this->whatsapp_code_expires_at->isFuture();
    }

    /**
     * E-mail de recuperação de senha em português (o padrão do framework
     * chega todo em inglês). Vale pro site e pro app — mesmo broker.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
