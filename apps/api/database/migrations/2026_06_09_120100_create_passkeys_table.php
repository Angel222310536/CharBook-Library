<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('credential_id', 512)->unique(); // base64
            $table->text('public_key');
            $table->string('label', 80)->default('Passkey');
            $table->string('device_type', 30)->nullable(); // platform | cross-platform
            $table->string('transports', 80)->nullable(); // usb,nfc,internal,…
            $table->unsignedBigInteger('counter')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
