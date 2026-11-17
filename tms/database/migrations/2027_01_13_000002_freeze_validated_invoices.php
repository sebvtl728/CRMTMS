<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A validated invoice or credit note is a frozen accounting record
     * (spec 0.9 § 4.4), enforced by PostgreSQL whatever the client:
     *
     *  - invoices: a draft changes and is deleted freely (its validation is
     *    its last change); once validated or sent, only the sending may
     *    change (status validated → sent, sent_at, email_status,
     *    email_error_code, and the technical updated_at); never deleted;
     *  - invoice_lines: no line is added to, changed on or removed from an
     *    invoice that is not a draft, nor written onto an invoice that is
     *    not visible (another organisme's); only a delete goes when the
     *    invoice is not visible any more (the cascade of a deleted draft);
     *  - invoice_journal: append-only, as audit_logs; position = the order
     *    of the chain in the organisme (1, 2, …), unique.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create or replace function invoices_immutable() returns trigger language plpgsql as $$
            declare
                -- recipient_key is generated (null in NEW before the trigger): it follows funding_id, compared.
                sending constant text[] := array['status', 'sent_at', 'email_status', 'email_error_code', 'updated_at', 'recipient_key'];
            begin
                if old.status = 'draft' then
                    return case when tg_op = 'DELETE' then old else new end;
                end if;
                if tg_op = 'DELETE' then
                    raise exception 'invoice % is validated: immutable', old.number;
                end if;
                if new.status not in ('validated', 'sent') or (old.status = 'sent' and new.status <> 'sent')
                    or (to_jsonb(new) - sending) is distinct from (to_jsonb(old) - sending) then
                    raise exception 'invoice % is validated: immutable', old.number;
                end if;
                return new;
            end;
            $$
        SQL);
        DB::statement('create trigger invoices_immutable before update or delete on invoices for each row execute function invoices_immutable()');

        DB::statement(<<<'SQL'
            create or replace function invoice_lines_immutable() returns trigger language plpgsql as $$
            declare
                invoice_status text;
            begin
                select status into invoice_status from invoices
                    where id = case when tg_op = 'INSERT' then new.invoice_id else old.invoice_id end;
                if tg_op = 'UPDATE' and new.invoice_id is distinct from old.invoice_id then
                    raise exception 'the invoice of a line never changes';
                end if;
                if invoice_status is null then
                    -- Not visible: the cascade of a deleted draft may remove its lines; nothing else goes
                    -- (a line written onto another organisme's invoice).
                    if tg_op = 'DELETE' then
                        return old;
                    end if;
                    raise exception 'the invoice of this line is not found: immutable';
                end if;
                if invoice_status <> 'draft' then
                    raise exception 'lines of a validated invoice are immutable';
                end if;
                return case when tg_op = 'DELETE' then old else new end;
            end;
            $$
        SQL);
        DB::statement('create trigger invoice_lines_immutable before insert or update or delete on invoice_lines for each row execute function invoice_lines_immutable()');

        DB::statement('alter table invoice_journal add column position bigint not null');
        DB::statement('alter table invoice_journal add constraint invoice_journal_position_unique unique (tenant_id, position)');
        DB::statement(<<<'SQL'
            create or replace function invoice_journal_immutable() returns trigger language plpgsql as $$
            begin
                raise exception 'invoice_journal is append-only';
            end;
            $$
        SQL);
        DB::statement('create trigger invoice_journal_immutable before update or delete on invoice_journal for each row execute function invoice_journal_immutable()');
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists invoice_journal_immutable on invoice_journal');
        DB::statement('drop function if exists invoice_journal_immutable()');
        DB::statement('alter table invoice_journal drop constraint if exists invoice_journal_position_unique');
        DB::statement('alter table invoice_journal drop column if exists position');
        DB::statement('drop trigger if exists invoice_lines_immutable on invoice_lines');
        DB::statement('drop function if exists invoice_lines_immutable()');
        DB::statement('drop trigger if exists invoices_immutable on invoices');
        DB::statement('drop function if exists invoices_immutable()');
    }
};
