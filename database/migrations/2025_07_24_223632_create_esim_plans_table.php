<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('esim_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_id')->unique();
            $table->unsignedBigInteger('package_id')->nullable(); // убрали unique()
            $table->string('plan_name');
            $table->string('period')->nullable();
            $table->string('capacity')->nullable();
            $table->string('capacity_unit')->nullable();
            $table->string('capacity_info')->nullable();
            $table->string('currency', 10)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->json('prices')->nullable();
            $table->string('price_info')->nullable();
            $table->text('country_code');
            $table->text('country');
            $table->json('coverages')->nullable();
            $table->text('targets')->nullable();
            $table->text('direct_link')->nullable();
            $table->string('url')->nullable();

            // Поле embedding для хранения вектора
            $table->json('embedding')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('esim_plans');
    }
};
