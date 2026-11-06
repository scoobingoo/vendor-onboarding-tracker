<?php

namespace Tests\Feature;

use App\Models\StageTransition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

class AuditLogTest extends DemoDataTestCase
{
    public function test_cannot_be_edited_or_deleted_even_directly_in_the_database(): void
    {
        $count = StageTransition::count();

        try {
            DB::table('stage_transitions')->update(['note' => 'rewritten']);
            $this->fail('UPDATE should have been rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('stage_transitions')->delete();
            $this->fail('DELETE should have been rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame($count, StageTransition::count());
        $this->assertSame(0, StageTransition::where('note', 'rewritten')->count());
    }

    public function test_the_model_refuses_updates_and_deletes(): void
    {
        $transition = StageTransition::first();

        $this->assertThrows(fn () => $transition->update(['note' => 'rewritten']), LogicException::class, 'append-only');
        $this->assertThrows(fn () => $transition->delete(), LogicException::class, 'append-only');
    }
}
