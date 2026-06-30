#!/bin/bash

echo "generating grpc files..."

sudo docker exec laravel_app protoc \
	--proto_path=grpc/protos \
	--php_out=grpc/generated \
	--grpc_out=grpc/generated \
	--plugin=protoc-gen-grpc=/usr/local/bin/grpc_php_plugin \
	grpc/protos/matchmaking.proto

sudo chown -R $USER:$USER grpc/generated/

echo "done!"
