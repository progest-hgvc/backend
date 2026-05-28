<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UsuarioSetorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Vincula os usuários de teste a todos os setores do sistema,
     * cada um com seu respectivo perfil.
     *
     * Perfis:
     *  - Jean Solicitante (ID=2) → perfil 'solicitante' em todos os setores
     *  - Arthur Almoxarife (ID=3) → perfil 'almoxarife' em todos os setores
     *  - Pablo Admin (ID=4) → perfil 'admin' em todos os setores
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        // Buscar usuários pelo e-mail para garantir ID correto independente do ambiente
        $userSolicitante = DB::table('users')->where('email', 'jeansolicitante@gmail.com')->first();
        $userAlmoxarife  = DB::table('users')->where('email', 'arthuralmoxarife@gmail.com')->first();
        $userAdmin       = DB::table('users')->where('email', 'pabloadmin@gmail.com')->first();

        if (!$userSolicitante || !$userAlmoxarife || !$userAdmin) {
            $this->command->error('Usuários de teste não encontrados. Execute UsuariosSeeder primeiro.');
            return;
        }

        // Buscar todos os setores cadastrados (agora pegamos o objeto inteiro para validar o nome)
        $setores = DB::table('setores')->get();

        if ($setores->isEmpty()) {
            $this->command->error('Nenhum setor encontrado. Execute SetoresSeeder primeiro.');
            return;
        }

        // Mapa de usuários e seus perfis
        $vinculos = [
            ['usuario_id' => $userSolicitante->id, 'perfil' => 'solicitante'],
            ['usuario_id' => $userAlmoxarife->id,  'perfil' => 'almoxarife'],
            ['usuario_id' => $userAdmin->id,        'perfil' => 'admin'],
        ];

        foreach ($vinculos as $vinculo) {
            foreach ($setores as $setor) {
                // Regra 1: CAF (Setor raiz sem distribuidor) não pode ter 'solicitante'
                $hasDistribuidor = DB::table('setor_distribuidor')->where('setor_solicitante_id', $setor->id)->exists();
                if (!$hasDistribuidor && $vinculo['perfil'] === 'solicitante') {
                    continue; // Pula vinculação
                }

                // Regra 2: Setores sem estoque não podem ter 'almoxarife'
                if (!$setor->estoque && $vinculo['perfil'] === 'almoxarife') {
                    continue; // Pula vinculação
                }

                DB::table('usuario_setor')->updateOrInsert(
                    [
                        'usuario_id' => $vinculo['usuario_id'],
                        'setor_id'   => $setor->id,
                    ],
                    [
                        'perfil'     => $vinculo['perfil'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }
}
