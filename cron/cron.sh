#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )

cd $DIR
mkdir -p locks
mkdir -p logs

./rotate.sh

touch logs/zkb.log

for each in $(ls *.php | grep -v nolock); do
	touch locks/$each.lock
	touch logs/$each.lock
	{
		flock -x -w 55 locks/$each.lock nice -n 19 php $each >> logs/$each.log 2>&1
	} &
done

for each in $(ls *.php | grep nolock); do
	touch logs/$each.log
	{
		nice -n 19 php $each >> logs/$each.log 2>&1
	} &
done
