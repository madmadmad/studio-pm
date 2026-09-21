<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->default('#595F64');
            $table->timestamps();
        });

        foreach ([
            ['name' => 'Software & Subscriptions', 'color' => '#3D6B5C'],
            ['name' => 'Equipment', 'color' => '#F41347'],
            ['name' => 'Travel', 'color' => '#B15CE6'],
            ['name' => 'Office Supplies', 'color' => '#595F64'],
            ['name' => 'Contractors', 'color' => '#D98E04'],
            ['name' => 'Advertising', 'color' => '#2C7BE5'],
        ] as $category) {
            DB::table('expense_categories')->insert($category + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
