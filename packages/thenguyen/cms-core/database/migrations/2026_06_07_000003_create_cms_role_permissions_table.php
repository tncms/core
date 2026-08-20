<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_role_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('cms_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('cms_permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id'], 'cms_role_permissions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_role_permissions');
    }
};
