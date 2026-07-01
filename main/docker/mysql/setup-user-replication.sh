#!/bin/bash
set -e

echo "Waiting for user service MySQL instances..."
sleep 15

echo "Configuring user service replica..."
mysql -h mysql_user_replica -uroot -p${DB_ROOT_PASSWORD} <<EOF
STOP REPLICA;

CHANGE REPLICATION SOURCE TO
    SOURCE_HOST='mysql_user_primary',
    SOURCE_USER='${DB_REPL_USER}',
    SOURCE_PASSWORD='${DB_REPL_PASSWORD}',
    SOURCE_AUTO_POSITION=1;

START REPLICA;
EOF

echo "User service replication setup complete."
mysql -h mysql_user_replica -uroot -p${DB_ROOT_PASSWORD} \
  -e "SHOW REPLICA STATUS\G" | grep -E "Replica_IO_Running|Replica_SQL_Running"
