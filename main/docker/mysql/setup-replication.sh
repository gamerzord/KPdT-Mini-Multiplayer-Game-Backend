#!/bin/bash
set -e

echo "Waiting for both MySQL instances to be ready..."
sleep 15

echo "Getting primary status..."
PRIMARY_STATUS=$(mysql -h mysql_primary -uroot -p${DB_ROOT_PASSWORD} -e "SHOW MASTER STATUS\G")
echo "$PRIMARY_STATUS"

echo "Configuring replica to follow primary..."
mysql -h mysql_replica -uroot -p${DB_ROOT_PASSWORD} <<EOF
STOP REPLICA;

CHANGE REPLICATION SOURCE TO
    SOURCE_HOST='mysql_primary',
    SOURCE_USER='${DB_REPL_USER}',
    SOURCE_PASSWORD='${DB_REPL_PASSWORD}',
    SOURCE_AUTO_POSITION=1;

START REPLICA;
EOF

echo "Checking replica status..."
mysql -h mysql_replica -uroot -p${DB_ROOT_PASSWORD} -e "SHOW REPLICA STATUS\G" | grep -E "Replica_IO_Running|Replica_SQL_Running"

echo "Replication setup complete."
