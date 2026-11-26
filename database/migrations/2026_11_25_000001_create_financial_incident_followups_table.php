<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_incident_followups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->string('source_type', 64);
            $table->uuid('source_id');
            $table->string('status', 32)->default('detected');
            $table->uuid('assigned_to')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->string('resolution_type', 64)->nullable();
            $table->text('resolution_note')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['store_id', 'source_type', 'source_id'], 'financial_incident_followups_source_unique');
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'assigned_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_incident_followups');
    }
};
