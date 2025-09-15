<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->string('tenant_id')->unique(); // Tenant identifier
            $table->string('name'); // Vendor's name
            $table->string('email')->unique(); // Vendor's email
            $table->enum('subscription_status', ['active', 'inactive'])->default('active'); // Subscription status
            $table->timestamps(); // Created at and Updated at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
