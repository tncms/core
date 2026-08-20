<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_role_user', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('cms_roles')->cascadeOnDelete();
            // No FK constraint on user_id: the users table is owned by the host
            // app and its key type/engine is outside the core package's control.
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();

            $table->unique(['role_id', 'user_id'], 'cms_role_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_role_user');
    }
};
