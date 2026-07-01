const EventEmitter = require('events');

const STATE = { FOLLOWER: 'follower', CANDIDATE: 'candidate', LEADER: 'leader' };

class RaftNode extends EventEmitter {
  constructor(nodeId, peers) {
    super();

    this.nodeId  = nodeId;  // e.g. 'raft_node_1'
    this.peers   = peers;   // e.g. ['http://raft_node_2:4001', 'http://raft_node_3:4001']

    // Persistent state (would be saved to disk in production)
    this.currentTerm = 0;   // election round, monotonically increases
    this.votedFor    = null; // who we voted for in current term
    this.log         = [];   // command log (not used in leader election only impl)

    // Volatile state
    this.state       = STATE.FOLLOWER;
    this.leaderId    = null;
    this.votesReceived = 0;
    this.lastHeartbeat = Date.now(); // Track when we last heard from leader

    // Timers
    this.electionTimer   = null;
    this.heartbeatTimer  = null;

    this.resetElectionTimer();
    console.log(`[${this.nodeId}] Started as FOLLOWER (term ${this.currentTerm})`);
  }

  // ── Timer helpers ─────────────────────────────────────

  // Random timeout between 300-600ms — wider range prevents split votes
  // Also added nodeId-based offset to prevent all nodes timing out together
  randomElectionTimeout() {
    // Base timeout 300-600ms (increased from 150-300ms)
    const baseTimeout = Math.floor(Math.random() * 300) + 300;
    
    // Add small offset based on nodeId to prevent all nodes timing out at once
    // This is an extra safety measure
    const nodeOffset = parseInt(this.nodeId.split('_')[2]) * 10;
    
    return baseTimeout + nodeOffset;
  }

  resetElectionTimer() {
    clearTimeout(this.electionTimer);
    this.electionTimer = setTimeout(
      () => this.startElection(),
      this.randomElectionTimeout()
    );
  }

  stopElectionTimer() {
    clearTimeout(this.electionTimer);
  }

  // ── Core Raft states ──────────────────────────────────

  startElection() {
    // FIX: Don't start election if we have a leader AND we've heard from them recently
    // If leader hasn't sent heartbeat in 2 election cycles, it's probably dead
    const heartbeatTimeout = this.randomElectionTimeout() * 2;
    const timeSinceLastHeartbeat = Date.now() - this.lastHeartbeat;
    
    if (this.leaderId && this.leaderId !== this.nodeId && timeSinceLastHeartbeat < heartbeatTimeout) {
      console.log(`[${this.nodeId}] Leader ${this.leaderId} is alive (heartbeat ${timeSinceLastHeartbeat}ms ago), not starting election`);
      this.resetElectionTimer();
      return;
    }

    // If we had a leader but no heartbeat, clear it
    if (this.leaderId && timeSinceLastHeartbeat >= heartbeatTimeout) {
      console.log(`[${this.nodeId}] Leader ${this.leaderId} seems dead (no heartbeat for ${timeSinceLastHeartbeat}ms), starting election`);
      this.leaderId = null;
    }

    // Become candidate, increment term, vote for self
    this.state       = STATE.CANDIDATE;
    this.currentTerm += 1;
    this.votedFor    = this.nodeId;
    this.votesReceived = 1; // vote for self

    console.log(`[${this.nodeId}] Starting election for term ${this.currentTerm}`);

    this.resetElectionTimer(); // restart timer in case this election fails

    // Ask every peer for their vote
    this.peers.forEach(peer => this.requestVote(peer));
  }

  async requestVote(peerUrl) {
    try {
      const res = await fetch(`${peerUrl}/vote`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          term:        this.currentTerm,
          candidateId: this.nodeId,
        }),
        signal: AbortSignal.timeout(100), // don't wait forever for a dead node
      });

      const { voteGranted, term } = await res.json();

      // If peer has higher term, we're behind — revert to follower
      if (term > this.currentTerm) {
        this.becomeFollower(term);
        return;
      }

      if (voteGranted && this.state === STATE.CANDIDATE) {
        this.votesReceived += 1;

        const majority = Math.floor((this.peers.length + 1) / 2) + 1;
        if (this.votesReceived >= majority) {
          this.becomeLeader();
        }
      }

    } catch (err) {
      // Peer is down or slow — that's expected in distributed systems, just skip
      console.log(`[${this.nodeId}] Peer ${peerUrl} unreachable during vote request`);
    }
  }

  becomeLeader() {
    this.state    = STATE.LEADER;
    this.leaderId = this.nodeId;
    this.lastHeartbeat = Date.now(); // Update heartbeat time
    this.stopElectionTimer(); // leader doesn't need election timer

    console.log(`[${this.nodeId}] Became LEADER for term ${this.currentTerm}`);
    this.emit('leader'); // notify server layer

    // Send heartbeat immediately, then every 50ms
    this.sendHeartbeats();
    this.heartbeatTimer = setInterval(() => this.sendHeartbeats(), 50);
  }

  becomeFollower(term) {
    this.state       = STATE.FOLLOWER;
    this.currentTerm = term;
    this.votedFor    = null;

    clearInterval(this.heartbeatTimer);
    this.resetElectionTimer();

    console.log(`[${this.nodeId}] Became FOLLOWER (term ${this.currentTerm})`);
    this.emit('follower');
  }

  async sendHeartbeats() {
    this.peers.forEach(async peer => {
      try {
        await fetch(`${peer}/heartbeat`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            term:     this.currentTerm,
            leaderId: this.nodeId,
          }),
          signal: AbortSignal.timeout(100),
        });
      } catch (err) {
        // Peer is down — that's fine, keep sending to others
      }
    });
  }

  // ── Handlers called by HTTP server ───────────────────

  // Another node is asking us to vote for them
  handleVoteRequest(candidateId, term) {
    // If candidate has stale term, reject
    if (term < this.currentTerm) {
      return { voteGranted: false, term: this.currentTerm };
    }

    // If we see a higher term, update ours and revert to follower
    if (term > this.currentTerm) {
      this.becomeFollower(term);
    }

    // Grant vote if we haven't voted yet this term
    const canVote = this.votedFor === null || this.votedFor === candidateId;
    if (canVote) {
      this.votedFor = candidateId;
      this.resetElectionTimer(); // reset timer since we acknowledged a valid candidate
      console.log(`[${this.nodeId}] Voted for ${candidateId} in term ${term}`);
      return { voteGranted: true, term: this.currentTerm };
    }

    return { voteGranted: false, term: this.currentTerm };
  }

  // Leader is sending us a heartbeat
  handleHeartbeat(leaderId, term) {
    if (term < this.currentTerm) {
      return { success: false, term: this.currentTerm };
    }

    // Valid heartbeat — reset election timer, update leader and heartbeat time
    this.leaderId = leaderId;
    this.lastHeartbeat = Date.now(); // ✅ Track when we heard from leader
    
    if (term > this.currentTerm) {
      this.becomeFollower(term);
    } else if (this.state !== STATE.FOLLOWER) {
      this.becomeFollower(term);
    } else {
      this.resetElectionTimer(); // just reset timer, stay follower
    }

    return { success: true, term: this.currentTerm };
  }

  getStatus() {
    return {
      nodeId:      this.nodeId,
      state:       this.state,
      term:        this.currentTerm,
      leaderId:    this.leaderId,
      isLeader:    this.state === STATE.LEADER,
    };
  }
}

module.exports = { RaftNode, STATE };
