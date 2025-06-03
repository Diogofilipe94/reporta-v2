<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReportDetailsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('report_details')) {
            Schema::create('report_details', function (Blueprint $table) {
                $table->id();
                $table->foreignId('report_id')->constrained()->onDelete('cascade');
                $table->text('technical_description')->nullable();
                $table->enum('priority', ['baixa', 'media', 'alta'])->default('media');
                $table->text('resolution_notes')->nullable();
                $table->decimal('estimated_cost', 10, 2)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('report_details');
    }
}
