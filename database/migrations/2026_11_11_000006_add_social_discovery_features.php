<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 32)->nullable()->unique();
            $table->string('bio', 500)->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->string('home_city', 100)->nullable();
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->char('country_code', 2)->default('CM');
            $table->string('country_name', 80)->default('Cameroon');
            $table->string('region', 80);
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['country_code', 'region', 'name']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('home_city_id')->nullable()->constrained('cities')->nullOnDelete();
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->string('trip_type', 20)->default('leisure');
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('photo_path', 500)->nullable();
            $table->index(['city_id', 'visibility', 'status', 'start_at']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']);
        });

        Schema::create('trip_join_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->text('message')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['trip_id', 'user_id']);
            $table->index(['trip_id', 'status', 'created_at']);
        });

        Schema::create('trip_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewee_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('rating');
            $table->string('title', 100)->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
            $table->unique(['trip_id', 'reviewer_id', 'reviewee_id']);
            $table->index(['reviewee_id', 'created_at']);
        });

        Schema::create('coin_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('coin_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('type', 20)->default('gift');
            $table->string('note', 180)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['recipient_id', 'created_at']);
            $table->index(['sender_id', 'created_at']);
        });

        DB::table('cities')->insertOrIgnore(array_map(static function (array $row): array {
            return ['country_code' => 'CM', 'country_name' => 'Cameroon', 'region' => $row[0], 'name' => $row[1], 'slug' => \Illuminate\Support\Str::slug($row[1].'-'.$row[0]), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }, [
            ['Littoral','Douala'],['Littoral','Edéa'],['Littoral','Nkongsamba'],
            ['Centre','Yaoundé'],['Centre','Mbalmayo'],['Centre','Obala'],
            ['South-West','Buea'],['South-West','Limbe'],['South-West','Kumba'],['South-West','Tiko'],
            ['West','Bafoussam'],['West','Dschang'],['West','Mbouda'],
            ['North-West','Bamenda'],['North-West','Kumbo'],['North-West','Wum'],
            ['South','Kribi'],['South','Ebolowa'],['South','Sangmélima'],
            ['North','Garoua'],['North','Maroua'],['Adamawa','Ngaoundéré'],
            ['East','Bertoua'],['East','Batouri'],['East','Abong-Mbang'],
            ['Adamawa','Meiganga'],['Far North','Kousséri'],['Far North','Mokolo'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_transactions');
        Schema::dropIfExists('coin_accounts');
        Schema::dropIfExists('trip_reviews');
        Schema::dropIfExists('trip_join_requests');
        Schema::dropIfExists('notifications');
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropIndex(['city_id', 'visibility', 'status', 'start_at']);
            $table->dropConstrainedForeignId('city_id');
            $table->dropColumn(['trip_type', 'photo_path']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('home_city_id');
        });
        Schema::dropIfExists('cities');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'bio', 'avatar_url', 'home_city']);
        });
    }
};
