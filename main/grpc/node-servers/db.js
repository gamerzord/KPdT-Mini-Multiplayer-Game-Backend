const mysql = require('mysql2/promise');
const redis = require('redis');

// MySQL connection pool — reused across all gRPC calls
const writePool = mysql.createPool({
  host: process.env.DB_PRIMARY_HOST || 'mysql_primary',
  port: process.env.DB_PORT || 3306,
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_DATABASE,
  waitForConnections: true,
  connectionLimit: 10,
});

const readPool = mysql.createPool({
  host: process.env.DB_READ_HOST || 'mysql_replica',
  port: process.env.DB_PORT || 3306,
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_DATABASE,
  waitForConnections: true,
  connectionLimit: 10,
});

// User Service DB — separate database entirely
const userWritePool = mysql.createPool({
  host:     process.env.USER_DB_WRITE_HOST || 'mysql_user_primary',
  port:     process.env.DB_PORT            || 3306,
  user:     process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  database: process.env.USER_DB_DATABASE   || 'user_service_db',
  waitForConnections: true,
  connectionLimit: 10,
});

const userReadPool = mysql.createPool({
  host:     process.env.USER_DB_READ_HOST || 'mysql_user_replica',
  port:     process.env.DB_PORT           || 3306,
  user:     process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  database: process.env.USER_DB_DATABASE  || 'user_service_db',
  waitForConnections: true,
  connectionLimit: 10,
});

// Redis client
const redisClient = redis.createClient({
  socket: {
    host: process.env.REDIS_HOST || 'redis',
    port: process.env.REDIS_PORT || 6379,
  },
});

redisClient.on('error', (err) => console.error('Redis error:', err));

// Connect once on startup
async function initRedis() {
  if (!redisClient.isOpen) {
    await redisClient.connect();
  }
}

module.exports = { writePool, readPool, userWritePool, userReadPool, redisClient, initRedis };
