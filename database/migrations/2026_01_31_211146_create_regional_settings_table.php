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
        Schema::create('regional_settings', function (Blueprint $table) {
            $table->id();
            $table->string('country_code')->unique(); // ES, US, MX...
            $table->string('country_name')->nullable();
            $table->decimal('multiplier', 5, 2)->default(1.0);
            $table->decimal('hourly_rate', 8, 2)->default(50.00);
            $table->json('extra_data')->nullable(); // Para el futuro
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regional_settings');
    }
};
