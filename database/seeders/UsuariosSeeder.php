<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UsuariosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Cria os usuários de teste do sistema.
     * O usuário admin padrão (ID=1) é criado via migration.
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        $usuarios = [
            // ID = 2 — Solicitante
            [
                'name'            => 'Jean Solicitante',
                'email'           => 'jeansolicitante@gmail.com',
                'password'        => Hash::make('Admin123'),
                'cpf'             => '11111111111',
                'telefone'        => '00000000000',
                'data_nascimento' => '1990-01-01',
                'status'          => 'A',
                'tipo_vinculo'    => 1,
            ],
            // ID = 3 — Almoxarife
            [
                'name'            => 'Arthur Almoxarife',
                'email'           => 'arthuralmoxarife@gmail.com',
                'password'        => Hash::make('Admin123'),
                'cpf'             => '22222222222',
                'telefone'        => '00000000000',
                'data_nascimento' => '1990-01-01',
                'status'          => 'A',
                'tipo_vinculo'    => 1,
            ],
            // ID = 4 — Admin
            [
                'name'            => 'Pablo Admin',
                'email'           => 'pabloadmin@gmail.com',
                'password'        => Hash::make('Admin123'),
                'cpf'             => '33333333333',
                'telefone'        => '00000000000',
                'data_nascimento' => '1990-01-01',
                'status'          => 'A',
                'tipo_vinculo'    => 1,
            ],
        ];

        foreach ($usuarios as $dados) {
            DB::table('users')->updateOrInsert(
                ['email' => $dados['email']],
                array_merge($dados, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
}
