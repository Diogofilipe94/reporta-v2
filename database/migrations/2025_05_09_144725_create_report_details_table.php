<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('report_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_id')->unique();
            $table->text('technical_description');
            $table->string('priority');
            $table->text('resolution_notes')->nullable();
            $table->decimal('estimated_cost', 8, 2)->nullable();
            $table->timestamps();

            $table->foreign('report_id')
                ->references('id')
                ->on('reports')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_details');
    }
};
