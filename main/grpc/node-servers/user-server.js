const grpc = require('@grpc/grpc-js');
const protoLoader = require('@grpc/proto-loader');
const path = require('path');
const { userWritePool, userReadPool } = require('./db');


const PROTO_PATH = path.join(__dirname, '../protos/matchmaking.proto');
const packageDefinition = protoLoader.loadSync(PROTO_PATH, {
  keepCase: false,
  longs: String,
  enums: String,
  defaults: true,
  oneofs: true,
});

const matchmakingProto = grpc.loadPackageDefinition(packageDefinition).matchmaking;

async function validatePlayer(call, callback) {
  const { userId } = call.request;

  try {
    const [[user]] = await userReadPool.query(
      'SELECT * FROM users WHERE id = ?',
      [userId]
    );

    if (!user) {
      return callback(null, { exists: false, name: '', score: 0 });
    }

    callback(null, {
      exists: true,
      name: user.name,
      score: user.score,
    });

  } catch (err) {
    callback({ code: grpc.status.INTERNAL, message: err.message });
  }
}

// ── NEW: UpdateUserScore ─────────────────────────────────
async function updateUserScore(call, callback) {
  const { userId, newScore } = call.request;

  try {
    const [[user]] = await userWritePool.query(
      'SELECT score FROM users WHERE id = ?', [userId]
    );

    if (!user) {
      return callback(null, { success: false, message: 'User not found', prevScore: 0 });
    }

    const prevScore = user.score;

    await userWritePool.query(
      'UPDATE users SET score = ? WHERE id = ?', [newScore, userId]
    );

    callback(null, { success: true, message: 'Score updated', prevScore });

  } catch (err) {
    callback({ code: grpc.status.INTERNAL, message: err.message });
  }
}

// ── NEW: CompensateUserScore — restore previous score ────
async function compensateUserScore(call, callback) {
  const { userId, prevScore } = call.request;

  try {
    await userWritePool.query(
      'UPDATE users SET score = ? WHERE id = ?', [prevScore, userId]
    );

    callback(null, { success: true, message: 'Score compensated (restored)' });

  } catch (err) {
    callback({ code: grpc.status.INTERNAL, message: err.message });
  }
}

function main() {
  const server = new grpc.Server();

  server.addService(matchmakingProto.UserService.service, {
    ValidatePlayer: validatePlayer,
    UpdateUserScore: updateUserScore,     // NEW
    CompensateUserScore: compensateUserScore, // NEW
  });

  server.bindAsync(
    '0.0.0.0:50052',
    grpc.ServerCredentials.createInsecure(),
    (err, port) => {
      if (err) {
        console.error('Failed to bind:', err);
        return;
      }
      console.log(`User gRPC server running on port ${port}`);
    }
  );
}

main();
