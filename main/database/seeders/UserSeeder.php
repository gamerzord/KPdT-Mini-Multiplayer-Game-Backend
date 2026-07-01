<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Ranking;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name'     => 'Player One',
                'email'    => 'player1@game.com',
                'password' => Hash::make('password123'),
                'score'    => 100,
            ],
            [
                'name'     => 'Player Two',
                'email'    => 'player2@game.com',
                'password' => Hash::make('password123'),
                'score'    => 80,
            ],
            [
                'name'     => 'Player Three',
                'email'    => 'player3@game.com',
                'password' => Hash::make('password123'),
                'score'    => 60,
            ],
            [
                'name'     => 'Player Four',
                'email'    => 'player4@game.com',
                'password' => Hash::make('password123'),
                'score'    => 40,
            ],
        ];

		foreach ($users as $userData) {
		    // Force the primary/write connection for both operations
		    $user = User::on('mysql')->firstOrCreate(
		        ['email' => $userData['email']],
		        $userData
		    );

		    Ranking::on('mysql')->firstOrCreate(
		        ['user_id' => $user->id],
		        [
		            'wins'   => 0,
		            'losses' => 0,
		            'points' => $user->score,
		        ]
		    );
		}
    }
}
