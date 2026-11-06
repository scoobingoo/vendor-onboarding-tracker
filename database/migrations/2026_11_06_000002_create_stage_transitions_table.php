<?php

use App\Domain\Stage;
use App\Domain\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail and source of truth for every vendor's stage. Triggers make
 * it append-only, so history can't be edited or deleted even with raw SQL.
 */
return new class extends Migration
{
    private const MESSAGE = 'stage_transitions is append-only';

    public function up(): void
    {
        Schema::create('stage_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained();
            // Null for the vendor's first entry (created by Sales at "Contract Sent").
            $table->enum('from_stage', Stage::values())->nullable();
            $table->enum('to_stage', Stage::values());
            // Null when the change came from outside the app (Sales / imported).
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->dateTime('changed_at');
            $table->string('note', Workflow::MAX_NOTE_LENGTH)->nullable();

            $table->index(['vendor_id', 'changed_at', 'id']);
        });

        foreach ($this->appendOnlyTriggers() as $sql) {
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_transitions');
    }

    /** @return list<string> */
    private function appendOnlyTriggers(): array
    {
        $message = self::MESSAGE;

        return match (DB::getDriverName()) {
            'sqlite' => [
                "CREATE TRIGGER stage_transitions_no_update BEFORE UPDATE ON stage_transitions
                 BEGIN SELECT RAISE(ABORT, '{$message}'); END",
                "CREATE TRIGGER stage_transitions_no_delete BEFORE DELETE ON stage_transitions
                 BEGIN SELECT RAISE(ABORT, '{$message}'); END",
            ],
            'mysql', 'mariadb' => [
                "CREATE TRIGGER stage_transitions_no_update BEFORE UPDATE ON stage_transitions
                 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
                "CREATE TRIGGER stage_transitions_no_delete BEFORE DELETE ON stage_transitions
                 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
            ],
            default => throw new RuntimeException(
                'Append-only triggers are only defined for SQLite and MySQL/MariaDB.'
            ),
        };
    }
};
