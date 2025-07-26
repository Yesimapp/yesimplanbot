<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id'); // FK на telegram_users.id

            $table->text('question'); // вопрос от пользователя
            $table->text('answer');   // ответ от бота

            $table->timestamps();

            $table->foreign('telegram_user_id')
                ->references('id')
                ->on('telegram_users')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('telegram_messages');
    }
};
