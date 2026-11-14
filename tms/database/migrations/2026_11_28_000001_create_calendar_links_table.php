<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outlook (Microsoft 365) links of the users, and the events pushed
     * through them.
     *
     *  - calendar_links: one per user; access and refresh tokens encrypted
     *    with APP_KEY (null once Microsoft refused the refresh: « À
     *    reconnecter »);
     *  - calendar_link_events: the Outlook event of an action in a user's
     *    calendar. transaction_id is sent with the creation so that a
     *    replayed creation does not duplicate the event; outlook_event_id
     *    is null until Microsoft answered.
     */
    public function up(): void
    {
        Schema::create('calendar_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('provider', 30)->default('microsoft365');
            $table->string('status', 20); // active | reconnect
            $table->text('access_token')->nullable();  // encrypted
            $table->text('refresh_token')->nullable(); // encrypted
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('connected_at', 6)->nullable();
            $table->timestampsTz(6);
        });
        Rls::enable('calendar_links');

        Schema::create('calendar_link_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('calendar_link_id')->constrained('calendar_links')->cascadeOnDelete();
            // An action of the CRM, read through its public service only (no foreign key across modules).
            $table->uuid('activity_id')->index();
            $table->uuid('transaction_id');
            $table->string('outlook_event_id', 512)->nullable();
            $table->timestampsTz(6);
            $table->unique(['calendar_link_id', 'activity_id']);
        });
        Rls::enable('calendar_link_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_link_events');
        Schema::dropIfExists('calendar_links');
    }
};
