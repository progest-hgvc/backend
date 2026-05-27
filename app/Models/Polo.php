<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Polo extends Model
{
    use HasFactory;

    protected $table = 'polos';

    protected $fillable = [
        'nome',
        'sigla',
        'status'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'sigla'      => 'string',
    ];

    // Relacionamentos
    public function setores()
    {
        return $this->hasMany(Setores::class, 'polo_id');
    }

    // Scopes para filtros
    public function scopeAtivo($query)
    {
        return $query->where('status', 'A');
    }

    public function scopeInativo($query)
    {
        return $query->where('status', 'I');
    }

    // Accessors
    public function isAtivo()
    {
        return $this->status === 'A';
    }
}
