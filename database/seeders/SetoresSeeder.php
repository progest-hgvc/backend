<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SetoresSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        // =====================================================================
        // Buscar IDs dos polos (tabela 'unidades' renomeada para 'polos')
        // =====================================================================
        $hgvc = DB::table('polos')->where('nome', 'Hospital Geral')->first();
        $hap  = DB::table('polos')->where('nome', 'Hospital Afrânio Peixoto')->first();
        $hcs  = DB::table('polos')->where('nome', 'Hospital Crescêncio Silveira')->first();
        $upa  = DB::table('polos')->where('nome', 'UPA')->first();

        if (!$hgvc || !$hap || !$hcs || !$upa) {
            $this->command->error('Polos não encontrados. Execute UnidadesSeeder primeiro.');
            return;
        }

        // =====================================================================
        // Setores — coluna polo_id (era unidade_id)
        // =====================================================================
        $setores = [
            // HGVC — com estoque
            ['polo_id' => $hgvc->id, 'nome' => 'FARMÁCIA CENTRAL',        'estoque' => true,  'status' => 'A', 'tipo' => 'Medicamento'],
            ['polo_id' => $hgvc->id, 'nome' => 'FARMÁCIA DE DISPENSAÇÃO', 'estoque' => true,  'status' => 'A', 'tipo' => 'Medicamento'],
            ['polo_id' => $hgvc->id, 'nome' => 'SATÉLITE DA EMERGÊNCIA',  'estoque' => true,  'status' => 'A', 'tipo' => 'Medicamento'],
            // HGVC — sem estoque
            ['polo_id' => $hgvc->id, 'nome' => 'CENTRO CIRÚRGICO',        'estoque' => false, 'status' => 'A', 'tipo' => 'Medicamento'],
            ['polo_id' => $hgvc->id, 'nome' => 'CLÍNICA MÉDICA',          'estoque' => false, 'status' => 'A', 'tipo' => 'Medicamento'],
            ['polo_id' => $hgvc->id, 'nome' => 'EMERGÊNCIA',              'estoque' => false, 'status' => 'A', 'tipo' => 'Medicamento'],

            // HAP — com estoque
            ['polo_id' => $hap->id,  'nome' => 'ALMOXARIFADO',            'estoque' => true,  'status' => 'A', 'tipo' => 'Medicamento'],
            ['polo_id' => $hap->id,  'nome' => 'UTI',                     'estoque' => true,  'status' => 'A', 'tipo' => 'Medicamento'],
            // HAP — sem estoque
            ['polo_id' => $hap->id,  'nome' => 'SETOR DE INTERNAÇÃO',     'estoque' => false, 'status' => 'A', 'tipo' => 'Medicamento'],

            // HCS — com estoque
            ['polo_id' => $hcs->id,  'nome' => 'ALMOXARIFADO',            'estoque' => true,  'status' => 'A', 'tipo' => 'Medicamento'],
            // HCS — sem estoque
            ['polo_id' => $hcs->id,  'nome' => 'CLÍNICA MÉDICA',          'estoque' => false, 'status' => 'A', 'tipo' => 'Medicamento'],
            ['polo_id' => $hcs->id,  'nome' => 'CLÍNICA CIRÚRGICA',       'estoque' => false, 'status' => 'A', 'tipo' => 'Medicamento'],

            // UPA — com estoque
            ['polo_id' => $upa->id,  'nome' => 'ALMOXARIFADO',            'estoque' => true,  'status' => 'A', 'tipo' => 'Medicamento'],
            // UPA — sem estoque
            ['polo_id' => $upa->id,  'nome' => 'ÁREA DE ATENDIMENTO',     'estoque' => false, 'status' => 'A', 'tipo' => 'Medicamento'],
            ['polo_id' => $upa->id,  'nome' => 'POSTO DE ENFERMAGEM',     'estoque' => false, 'status' => 'A', 'tipo' => 'Medicamento'],
        ];

        foreach ($setores as $setor) {
            DB::table('setores')->updateOrInsert(
                // Chave composta: polo_id + nome (evita duplicatas entre unidades diferentes)
                ['polo_id' => $setor['polo_id'], 'nome' => $setor['nome']],
                [
                    'descricao'  => null,
                    'tipo'       => $setor['tipo'],
                    'estoque'    => $setor['estoque'],
                    'status'     => $setor['status'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // =====================================================================
        // Relações de distribuição: setor_distribuidor
        // (tabela 'setor_fornecedor' renomeada para 'setor_distribuidor',
        //  coluna 'setor_fornecedor_id' renomeada para 'setor_distribuidor_id')
        // =====================================================================

        // Helper para buscar setor pelo nome e polo_id
        $setor = fn($nome, $poloId) => DB::table('setores')
            ->where('nome', $nome)
            ->where('polo_id', $poloId)
            ->first();

        // Referências reutilizadas
        $farmaciaCentral = $setor('FARMÁCIA CENTRAL',        $hgvc->id);
        $farmDisp        = $setor('FARMÁCIA DE DISPENSAÇÃO', $hgvc->id);
        $satEmerg        = $setor('SATÉLITE DA EMERGÊNCIA',  $hgvc->id);
        $centroCirc      = $setor('CENTRO CIRÚRGICO',        $hgvc->id);
        $clinicaMedHGVC  = $setor('CLÍNICA MÉDICA',          $hgvc->id);
        $emergencia      = $setor('EMERGÊNCIA',              $hgvc->id);

        $almoxHAP        = $setor('ALMOXARIFADO',            $hap->id);
        $utiHAP          = $setor('UTI',                     $hap->id);
        $internacaoHAP   = $setor('SETOR DE INTERNAÇÃO',     $hap->id);

        $almoxHCS        = $setor('ALMOXARIFADO',            $hcs->id);
        $clinicaMedHCS   = $setor('CLÍNICA MÉDICA',          $hcs->id);
        $clinicaCirHCS   = $setor('CLÍNICA CIRÚRGICA',       $hcs->id);

        $almoxUPA        = $setor('ALMOXARIFADO',            $upa->id);
        $atendimentoUPA  = $setor('ÁREA DE ATENDIMENTO',     $upa->id);
        $postoUPA        = $setor('POSTO DE ENFERMAGEM',     $upa->id);

        // Mapa de relações: [setor_solicitante, setor_distribuidor]
        $relacoes = [
            // HGVC
            [$farmDisp,       $farmaciaCentral],   // Farmácia de Dispensação -> Farmácia Central
            [$satEmerg,       $farmaciaCentral],   // Satélite da Emergência  -> Farmácia Central
            [$centroCirc,     $farmDisp],           // Centro Cirúrgico        -> Farmácia de Dispensação
            [$clinicaMedHGVC, $farmDisp],           // Clínica Médica (HGVC)   -> Farmácia de Dispensação
            [$emergencia,     $satEmerg],           // Emergência              -> Satélite da Emergência

            // HAP
            [$almoxHAP,       $farmaciaCentral],   // Almoxarifado (HAP) -> Farmácia Central (HGVC)
            [$utiHAP,         $almoxHAP],           // UTI                -> Almoxarifado (HAP)
            [$internacaoHAP,  $almoxHAP],           // Setor de Internação -> Almoxarifado (HAP)

            // HCS
            [$almoxHCS,       $farmaciaCentral],   // Almoxarifado (HCS) -> Farmácia Central (HGVC)
            [$clinicaMedHCS,  $almoxHCS],           // Clínica Médica     -> Almoxarifado (HCS)
            [$clinicaCirHCS,  $almoxHCS],           // Clínica Cirúrgica  -> Almoxarifado (HCS)

            // UPA
            [$almoxUPA,       $farmaciaCentral],   // Almoxarifado (UPA) -> Farmácia Central (HGVC)
            [$atendimentoUPA, $almoxUPA],           // Área de Atendimento -> Almoxarifado (UPA)
            [$postoUPA,       $almoxUPA],           // Posto de Enfermagem -> Almoxarifado (UPA)
        ];

        foreach ($relacoes as [$solicitante, $distribuidor]) {
            if (!$solicitante || !$distribuidor) {
                continue; // Pula se algum setor não foi encontrado
            }

            DB::table('setor_distribuidor')->updateOrInsert(
                [
                    'setor_solicitante_id'  => $solicitante->id,
                    'setor_distribuidor_id' => $distribuidor->id,
                ],
                [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
