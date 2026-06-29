<?php

namespace App\Services;

use App\Models\Matchs;
use App\Models\Ranking;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class MatchmakingService
{
    // Redis key where waiting players are stored as a list
    private const QUEUE_KEY = 'matchmaking:queue';

    public function joinQueue(int $userId): array
    {
        // Check if player is already in an ongoing match
        $existingMatch = Matchs::where('status', 'ongoing')
                              ->where(function ($q) use ($userId) {
                                  $q->where('player1_id', $userId)
                                    ->orWhere('player2_id', $userId);
                              })->first();

        if ($existingMatch) {
            return [
                'matched'  => true,
                'match_id' => $existingMatch->id,
                'message'  => 'You are already in a match',
            ];
        }

        // Check if anyone is already waiting in queue
        $waitingPlayerId = Redis::lpop(self::QUEUE_KEY);

        // Nobody waiting — add current player to queue
        if (!$waitingPlayerId || $waitingPlayerId == $userId) {
            Redis::rpush(self::QUEUE_KEY, $userId);
            // Expire queue entry after 5 mins so players don't get stuck
            Redis::expire(self::QUEUE_KEY, 300);

            return [
                'matched' => false,
                'message' => 'Waiting for opponent...',
            ];
        }

        // Someone is waiting — create the match using Two Phase Commit pattern
        // Begin transaction so both players are committed or neither is
        DB::beginTransaction();
        try {
            $match = Matchs::create([
                'player1_id' => $waitingPlayerId,
                'player2_id' => $userId,
                'status'     => 'ongoing',
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            // Put waiting player back in queue since match failed
            Redis::rpush(self::QUEUE_KEY, $waitingPlayerId);

            throw $e;
        }

        return [
            'matched'   => true,
            'match_id'  => $match->id,
            'opponent'  => $waitingPlayerId,
            'message'   => 'Match found!',
        ];
    }

    public function leaveQueue(int $userId): void
    {
        // lrem removes all occurrences of userId from the queue list
        // 0 = remove all occurrences, not just first
        Redis::lrem(self::QUEUE_KEY, 0, $userId);
    }

    public function getStatus(int $userId): array
    {
        // Check if in queue
        $queue    = Redis::lrange(self::QUEUE_KEY, 0, -1);
        $inQueue  = in_array($userId, $queue);

        // Check if in active match
        $match = Matchs::where('status', 'ongoing')
                      ->where(function ($q) use ($userId) {
                          $q->where('player1_id', $userId)
                            ->orWhere('player2_id', $userId);
                      })->with(['player1:id,name', 'player2:id,name'])
                        ->first();

        if ($match) {
            return [
                'state'    => 'in_match',
                'match_id' => $match->id,
                'opponent' => $match->player1_id === $userId
                                ? $match->player2
                                : $match->player1,
            ];
        }

        return [
            'state'   => $inQueue ? 'in_queue' : 'idle',
            'message' => $inQueue ? 'Waiting for opponent' : 'Not in queue',
        ];
    }

    public function finishMatch(int $matchId, int $winnerId): Matchs
    {
        $match = Matchs::findOrFail($matchId);
        $loserId = $match->player1_id === $winnerId
                    ? $match->player2_id
                    : $match->player1_id;

        // Saga Pattern — each step updates one thing
        // if any step fails, previous steps get compensated
        DB::beginTransaction();
        try {
            // Step 1 — mark match as finished
            $match->update([
                'status'    => 'finished',
                'winner_id' => $winnerId,
            ]);

            // Step 2 — update winner ranking
            Ranking::where('user_id', $winnerId)->update([
                'wins'   => DB::raw('wins + 1'),
                'points' => DB::raw('points + 10'),
            ]);

            // Step 3 — update loser ranking
            Ranking::where('user_id', $loserId)->update([
                'losses' => DB::raw('losses + 1'),
                'points' => DB::raw('GREATEST(points - 5, 0)'), // floor at 0
            ]);

            DB::commit();

            // Step 4 — bust Redis cache so leaderboard reflects new scores
            Redis::del('ranking:leaderboard');

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $match->fresh();
    }
}