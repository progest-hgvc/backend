<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProdutosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('produtos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('marca')->nullable();
            $table->string('codigo_simpas')->nullable();
            $table->string('codigo_barras')->nullable();
            $table->foreignId('grupo_produto_id')->constrained('grupo_produto')->onDelete('restrict');
            $table->foreignId('unidade_medida_id')->constrained('unidade_medida')->onDelete('restrict');
            $table->enum('status', ['A', 'I'])->default('A')->comment('A = Ativo, I = Inativo');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('produtos');
    }
}
