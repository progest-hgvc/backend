<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSiglaToPolosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('polos', function (Blueprint $table) {
            $table->string('sigla', 10)->nullable()->after('nome');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('polos', function (Blueprint $table) {
            $table->dropColumn('sigla');
        });
    }
}
