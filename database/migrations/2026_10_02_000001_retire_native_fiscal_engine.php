<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('fiscal_transmissions');
        Schema::dropIfExists('fiscal_tax_rules');
        Schema::dropIfExists('fiscal_tax_groups');
        Schema::dropIfExists('fiscal_documents');
        Schema::dropIfExists('fiscal_sequences');
        Schema::dropIfExists('fiscal_certificates');
        Schema::dropIfExists('fiscal_companies');
    }

    public function down(): void
    {
        // Archived implementation is restored as a whole, never as partial tables.
    }
};
