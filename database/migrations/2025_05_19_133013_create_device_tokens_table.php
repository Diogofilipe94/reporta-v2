<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('token')->unique();
            $table->string('platform');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Índice para melhorar a performance
            $table->index(['user_id', 'token']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('device_tokens');
    }
};
