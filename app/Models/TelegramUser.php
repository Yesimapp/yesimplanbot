<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model
{
    use HasFactory;

    // Указываем, какие поля можно массово заполнять
    protected $fillable = [
        'id', // обязательно, иначе updateOrCreate не сработает
        'username',
        'first_name',
        'last_name',
        'language_code',
        'is_bot',
    ];

    // Указываем, что id не автоинкрементный
    public $incrementing = false;

    // Указываем тип ключа (Telegram ID — это число)
    protected $keyType = 'int';
}
