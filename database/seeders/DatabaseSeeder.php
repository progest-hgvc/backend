<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Ordem de execução é importante pois há dependências entre tabelas:
     *  1. Polos (tabela 'polos', antes 'unidades')
     *  2. Setores + relações setor_distribuidor
     *  3. Usuários de teste
     *  4. Vínculo Usuário x Setor
     *  5. Dados de catálogo (unidade de medida, grupo, fornecedores, produtos)
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            UnidadesSeeder::class,        // Polos (HGVC, HAP, HCS, UPA)
            SetoresSeeder::class,         // Setores + setor_distribuidor
            UsuariosSeeder::class,        // Usuários de teste
            UsuarioSetorSeeder::class,    // Vínculo usuário x setor x perfil
            UnidadeMedidaSeeder::class,
            GrupoProdutoSeeder::class,
            FornecedoresSeeder::class,
            ProdutosSeeder::class,
        ]);
    }
}
