<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
        });

        Schema::create('demo_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        Schema::create('demo_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }
};
