<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_foundation_tables_are_created(): void
    {
        foreach ([
            'centers',
            'center_user',
            'languages',
            'levels',
            'courses',
        ] as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Expected the {$table} table to exist."
            );
        }
    }

    public function test_existing_users_table_is_reused(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertContains('email', Schema::getColumnListing('users'));
    }
}
