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
        Schema::table('users', function (Blueprint $table) {
            $table->string('cpf', 14)->unique()->nullable()->after('email');
            $table->string('rg', 20)->nullable()->after('cpf');
            $table->string('cnh', 20)->unique()->nullable()->after('rg');
            $table->enum('cnh_category', ['A', 'AB', 'AC', 'AD', 'AE'])->nullable()->after('cnh');
            $table->date('cnh_expiry_date')->nullable()->after('cnh_category');
            $table->string('phone', 20)->nullable()->after('cnh_expiry_date');
            $table->string('whatsapp', 20)->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('whatsapp');
            $table->string('address')->nullable()->after('birth_date');
            $table->string('city')->nullable()->after('address');
            $table->string('state', 2)->nullable()->after('city');
            $table->string('zip_code', 9)->nullable()->after('state');
            $table->enum('role', ['admin', 'employee', 'customer'])->default('customer')->after('zip_code');
            $table->boolean('is_verified')->default(false)->after('role');
            $table->json('documents')->nullable()->after('is_verified');
            $table->decimal('credit_limit', 10, 2)->default(0)->after('documents');
            $table->index('cpf');
            $table->index('cnh');
            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'cpf', 'rg', 'cnh', 'cnh_category', 'cnh_expiry_date',
                'phone', 'whatsapp', 'birth_date', 'address', 'city',
                'state', 'zip_code', 'role', 'is_verified', 'documents',
                'credit_limit'
            ]);
        });
    }
};
