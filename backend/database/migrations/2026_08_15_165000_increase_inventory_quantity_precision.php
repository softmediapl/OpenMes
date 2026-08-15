<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('materials', function (Blueprint $table): void {
                $table->decimal('stock_quantity', 14, 4)->default(0)->change();
                $table->decimal('reserved_quantity', 14, 4)->default(0)->change();
            });
            Schema::table('warehouse_stocks', function (Blueprint $table): void {
                $table->decimal('quantity', 14, 4)->default(0)->change();
            });
        }
        Schema::table('stock_document_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 14, 4)->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('materials', function (Blueprint $table): void {
                $table->decimal('stock_quantity', 12, 3)->default(0)->change();
                $table->decimal('reserved_quantity', 12, 3)->default(0)->change();
            });
            Schema::table('warehouse_stocks', function (Blueprint $table): void {
                $table->decimal('quantity', 14, 3)->default(0)->change();
            });
        }
        Schema::table('stock_document_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 14, 3)->change();
        });
    }
};
