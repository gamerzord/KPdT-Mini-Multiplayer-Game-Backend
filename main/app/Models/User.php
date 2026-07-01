<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{

	protected $connection = 'mysql_user';
	
    protected $fillable = [
        'name',
        'email', 
        'password',
        'score',
    ];

    protected $hidden = [
        'password',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'name'  => $this->name,
            'email' => $this->email,
        ];
    }

    public function ranking()
    {
        return $this->hasOne(Ranking::class);
    }

    public function matches()
    {
        return $this->hasMany(Matchs::class, 'player1_id')
                    ->orWhere('player2_id', $this->id);
    }
}
