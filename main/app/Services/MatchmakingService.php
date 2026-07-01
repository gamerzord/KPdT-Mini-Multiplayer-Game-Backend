<?php

namespace App\Services;

use App\Models\Matchs;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use App\Services\GrpcClients\RankingGrpcClient;
use App\Services\GrpcClients\UserGrpcClient;

class MatchmakingService
{
    // Redis key where waiting players are stored as a list
    private const QUEUE_KEY = 'matchmaking:queue';

    public function joinQueue(int $userId): array
    {

        // NEW — validate player exists via gRPC before doing anything
        $userGrpc = new UserGrpcClient();
        $playerInfo = $userGrpc->validatePlayer($userId);

        if (!$playerInfo['exists']) {
            throw new \Exception('Player does not exist');
        }

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
        $match   = Matchs::findOrFail($matchId);
        $loserId = $match->player1_id === $winnerId
                   ? $match->player2_id
                   : $match->player1_id;

        $rankingClient = new RankingGrpcClient();
        $userClient    = new UserGrpcClient();

        // Track what succeeded so we can compensate on failure
        $rankingUpdated  = false;
        $rankingSnapshot = [];  // store old values for compensation
        $scoreUpdated    = false;
        $prevScore       = 0;

        // ── Saga Step 1: Update match status (PHP → own DB) ──
        DB::beginTransaction();
        try {
            $match->update(['status' => 'finished', 'winner_id' => $winnerId]);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            // Step 1 failed before anything else ran, just throw
            throw new \Exception('Saga Step 1 failed (match update): ' . $e->getMessage());
        }

        // ── Saga Step 2: Update ranking (PHP → gRPC → Node DB) ──
        try {
            // Snapshot current values BEFORE updating so we can restore if needed
            $currentRank     = $rankingClient->getPlayerRank($winnerId);
            $rankingSnapshot = [
                'winner_points' => $currentRank['points'],
                'loser_points'  => $rankingClient->getPlayerRank($loserId)['points'],
            ];

            $rankingResult  = $rankingClient->updateRanking($winnerId, $loserId, $matchId);
            $rankingUpdated = true;

        } catch (\Exception $e) {
            // Step 2 failed — compensate Step 1 (revert match status)
            DB::beginTransaction();
            try {
                $match->update(['status' => 'ongoing', 'winner_id' => null]);
                DB::commit();
            } catch (\Exception $compensateEx) {
                DB::rollBack();
            }
            throw new \Exception('Saga Step 2 failed (ranking update): ' . $e->getMessage());
        }

        // ── Saga Step 3: Update user score (PHP → gRPC → Node DB) ──
        try {
            $newScore    = $rankingResult['winner_points'];
            $scoreResult = $userClient->updateUserScore($winnerId, $newScore);
            $prevScore   = $scoreResult['prev_score'];
            $scoreUpdated = true;

        } catch (\Exception $e) {
            // Step 3 failed — compensate Step 2 (undo ranking)
            if ($rankingUpdated) {
                $rankingClient->compensateRanking(
                    $winnerId,
                    $loserId,
                    $rankingSnapshot['winner_points'],
                    $rankingSnapshot['loser_points']
                );
            }

            // Compensate Step 1 (revert match status)
            DB::beginTransaction();
            try {
                $match->update(['status' => 'ongoing', 'winner_id' => null]);
                DB::commit();
            } catch (\Exception $compensateEx) {
                DB::rollBack();
            }

	    throw new \Exception('Saga Step 3 failed (score update): ' . $e->getMessage());
	}

        return $match->fresh();
    }
}
