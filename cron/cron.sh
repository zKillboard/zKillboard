#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )

cd $DIR
mkdir -p locks
mkdir -p logs

./rotate.sh

touch logs/zkb.log

# Check if we are master, if not, do not execute crons here
{
    php 0.masterCheck.php >> logs/0.masterCheck.php.log 2>&1
} &
{
    flock -x -w 65 locks/0.ztop.lock nice -n 19 php 0.ztop.php >> logs/$each.log 2>&1
} &
if [ ! -f ../isMaster.lock ] ; then exit ; fi

for each in $(ls *.php | grep -v nolock | grep -v ^0); do
	touch locks/$each.lock
	touch logs/$each.lock
	{
		flock -x -w 55 locks/$each.lock nice -n 19 php $each >> logs/$each.log 2>&1
	} &
done

for each in $(ls *.php | grep nolock | grep -v ^0); do
	touch logs/$each.log
	{
		nice -n 19 php $each >> logs/$each.log 2>&1
	} &
done
