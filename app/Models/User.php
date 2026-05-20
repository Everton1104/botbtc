<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'whatsapp', 'whatsapp_code', 'whatsapp_code_expires_at', 'whatsapp_verified_at', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
}
