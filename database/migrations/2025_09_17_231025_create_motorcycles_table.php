<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('motorcycles', function (Blueprint $table) {
            $table->id();
            $table->string('brand');
            $table->string('model');
            $table->year('year');
            $table->string('plate')->unique();
            $table->string('color');
            $table->integer('engine_capacity');
            $table->integer('mileage');
            $table->decimal('daily_rate', 10, 2);
            $table->enum('status', ['available', 'rented', 'maintenance', 'inactive'])->default('available');
            $table->text('description')->nullable();
            $table->json('features')->nullable();
            $table->json('images')->nullable();
            $table->timestamp('last_maintenance_at')->nullable();
            $table->timestamp('next_maintenance_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'daily_rate']);
            $table->index('brand');
            $table->index('model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('motorcycles');
    }
};
