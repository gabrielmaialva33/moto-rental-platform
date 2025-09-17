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
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('motorcycle_id')->constrained()->onDelete('cascade');
            $table->datetime('start_date');
            $table->datetime('end_date');
            $table->datetime('actual_return_date')->nullable();
            $table->decimal('daily_rate', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->decimal('security_deposit', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('additional_charges', 10, 2)->default(0);
            $table->text('additional_charges_description')->nullable();
            $table->enum('status', ['reserved', 'active', 'completed', 'cancelled'])->default('reserved');
            $table->enum('payment_status', ['pending', 'partial', 'paid', 'refunded'])->default('pending');
            $table->text('pickup_location')->nullable();
            $table->text('return_location')->nullable();
            $table->text('notes')->nullable();
            $table->json('insurance_details')->nullable();
            $table->integer('initial_mileage')->nullable();
            $table->integer('final_mileage')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['motorcycle_id', 'status']);
            $table->index(['start_date', 'end_date']);
            $table->index('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rentals');
    }
};
