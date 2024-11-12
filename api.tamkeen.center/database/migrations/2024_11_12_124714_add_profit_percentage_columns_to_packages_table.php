<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->integer('profit_percentage_in_level_1')->after('number_of_uses')->nullable();
            $table->integer('profit_percentage_in_level_2')->after('profit_percentage_in_level_1')->nullable();
            $table->integer('profit_percentage_in_level_3')->after('profit_percentage_in_level_2')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['profit_percentage_in_level_1', 'profit_percentage_in_level_2', 'profit_percentage_in_level_3']);
        });
    }
};
