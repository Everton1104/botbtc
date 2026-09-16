<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um token FCM (endereço de push de um celular) pertencente a um usuário.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $token
 * @property string|null $dispositivo
 */
class FcmToken extends Model
{
    protected $fillable = ['user_id', 'token', 'dispositivo'];
}
