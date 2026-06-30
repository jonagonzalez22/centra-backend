<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->string('customer_code', 20)->index();
            $table->uuid('commercial_group_id')->nullable();
            $table->string('display_name', 255)->index();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company_name', 255)->nullable();
            $table->uuid('document_type_id');
            $table->string('document_number', 50);
            $table->string('document_number_normalized', 50)->index();
            $table->text('search_text');
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('blocked_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');

            $table->foreign('commercial_group_id')
                ->references('id')
                ->on('commercial_groups')
                ->onDelete('set null');

            $table->foreign('document_type_id')
                ->references('id')
                ->on('document_types')
                ->onDelete('restrict');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->unique(['store_id', 'document_number_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
