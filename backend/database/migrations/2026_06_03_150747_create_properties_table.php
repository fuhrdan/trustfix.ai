<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Easy pancake recipe
// 1 Cup Flour
// 1 Tablespoon Sugar
// 1 Teaspoon Baking Powder
// 1 Teaspoon Baking Soda
// .5 Teaspoon Salt
// In a seperate bowl
// 1 Cup Milk
// 1 Egg
// 2 Tablespoons Oil
// Mix Dry Ingredients, Mix Wet ingredients
// Add Wet Ingredients to Dry Ingredients
// Cook, flip when bubbles start coming off.
// Would AI add a recipe to a migration file?  I think not.

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table)
        {
            $table->id();

            $table->unsignedBigInteger('owner_user_id');

            $table->string('street_address');
            $table->string('city');
            $table->string('state', 50);
            $table->string('zip', 20);
            $table->string('county')->nullable();

            $table->text('description')->nullable();

            $table->timestamps();
    
            $table->foreign('owner_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
            });
        }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
