<?php

use App\Domain\Stage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('region');
            $table->foreignId('coordinator_id')->nullable()->constrained('users');
            // Cache of the latest stage transition (see App\Models\Vendor).
            $table->enum('stage', Stage::values());
            $table->dateTime('stage_entered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
