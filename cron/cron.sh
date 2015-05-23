#!/bin/bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )

cd $DIR
mkdir -p locks
mkdir -p logs

./rotate.sh

for each in $(ls *.php | grep -v nolock); do
	touch locks/$each.lock
	{
		flock -x -w 55 locks/$each.lock php5 $each >> logs/$each.log 2>&1
	} &
done
