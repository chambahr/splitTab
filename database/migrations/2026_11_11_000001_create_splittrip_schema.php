
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('email')->unique();
            $t->string('phone', 32)->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('trips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users');
            $t->string('name', 140);
            $t->text('description')->nullable();
            $t->string('visibility', 12)->default('private');
            $t->string('location_mode', 24)->default('city');
            $t->string('destination', 180)->nullable();
            $t->timestampTz('start_at')->nullable();
            $t->timestampTz('end_at')->nullable();
            $t->string('timezone', 64)->default('UTC');
            $t->char('currency', 3)->default('EUR');
            $t->unsignedBigInteger('budget_minor')->default(0);
            $t->unsignedInteger('participant_limit')->nullable();
            $t->string('status', 20)->default('planning');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['visibility', 'status', 'start_at']);
        });

        Schema::create('trip_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16)->default('member');
            $t->string('status', 16)->default('active');
            $t->timestampTz('joined_at')->nullable();
            $t->timestampTz('last_private_verified_at')->nullable();
            $t->timestamps();
            $t->unique(['trip_id', 'user_id']);
        });

        Schema::create('trip_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invited_by')->constrained('users');
            $t->string('email');
            $t->char('token_hash', 64)->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['trip_id', 'email']);
        });

        Schema::create('trip_access_otps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('code_hash');
            $t->timestampTz('expires_at');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestampTz('consumed_at')->nullable();
            $t->timestamps();
            $t->index(['trip_id', 'user_id', 'created_at']);
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payer_id')->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->string('description', 180);
            $t->string('category', 32);
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('allocation_method', 16);
            $t->timestampTz('spent_at');
            $t->uuid('idempotency_key')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['trip_id', 'idempotency_key']);
            $t->index(['trip_id', 'spent_at']);
        });

        Schema::create('expense_splits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->unsignedBigInteger('share_minor');
            $t->unsignedInteger('percentage_basis_points')->nullable();
            $t->unsignedInteger('shares')->nullable();
            $t->text('comment')->nullable();
            $t->unsignedBigInteger('settled_minor')->default(0);
            $t->timestamps();
            $t->unique(['expense_id', 'user_id']);
        });

        Schema::create('expense_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->text('body');
            $t->timestamps();
        });

        Schema::create('settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->char('currency', 3);
            $t->string('status', 16)->default('proposed');
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('settlement_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_user_id')->constrained('users');
            $t->foreignId('to_user_id')->constrained('users');
            $t->unsignedBigInteger('amount_minor');
            $t->string('status', 16)->default('proposed');
            $t->timestampTz('paid_at')->nullable();
            $t->timestamps();
        });

        DB::statement('
            ALTER TABLE settlement_transfers
            ADD CONSTRAINT settlement_transfers_distinct_users_check
            CHECK (from_user_id <> to_user_id)
        ');

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 80);
            $t->string('subject_type', 80)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->jsonb('metadata')->default('{}');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['trip_id', 'created_at']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestampTz('last_used_at')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'personal_access_tokens',
            'audit_logs',
            'settlement_transfers',
            'settlements',
            'expense_comments',
            'expense_splits',
            'expenses',
            'trip_access_otps',
            'trip_invitations',
            'trip_members',
            'trips',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
