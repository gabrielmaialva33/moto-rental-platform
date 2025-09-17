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
        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorcycle_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['preventive', 'corrective', 'inspection']);
            $table->date('maintenance_date');
            $table->integer('mileage_at_maintenance');
            $table->decimal('cost', 10, 2);
            $table->text('description');
            $table->json('services_performed')->nullable();
            $table->json('parts_replaced')->nullable();
            $table->string('performed_by');
            $table->string('workshop')->nullable();
            $table->date('next_maintenance_date')->nullable();
            $table->integer('next_maintenance_mileage')->nullable();
            $table->timestamps();
            $table->index(['motorcycle_id', 'maintenance_date']);
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_records');
    }
};
