<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_incident_followup_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('financial_incident_followup_id');
            $table->string('action', 32);
            $table->uuid('actor_id')->nullable();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->uuid('previous_assigned_to')->nullable();
            $table->uuid('new_assigned_to')->nullable();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('financial_incident_followup_id', 'financial_incident_history_followup_fk')
                ->references('id')->on('financial_incident_followups')->cascadeOnDelete();
            $table->foreign('actor_id', 'financial_incident_history_actor_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('previous_assigned_to', 'financial_incident_history_previous_assignee_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('new_assigned_to', 'financial_incident_history_new_assignee_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['financial_incident_followup_id', 'created_at'], 'financial_incident_history_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_incident_followup_histories');
    }
};
