#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )

cd $DIR
mkdir -p locks
mkdir -p logs

./rotate.sh

touch logs/zkb.log

# Check if we are master, if not, do not execute crons here
{
    php 0.masterCheck.php >> logs/0.masterCheck.php.log 2> >(php ../scratch/errlogger.php)
} &
{
    flock -x -w 65 locks/0.ztop.lock nice -n 19 php 0.ztop.php >> logs/0.ztop.php.log 2> >(php ../scratch/errlogger.php)
} &
if [ ! -f ../isMaster.lock ] ; then exit ; fi

for each in $(ls *.php | grep -v nolock | grep -v ^0); do
	touch locks/$each.lock
	touch logs/$each.lock
	{
		flock -x -w 55 locks/$each.lock nice -n 19 php $each >> logs/$each.log 2> >(php ../scratch/errlogger.php)
	} &
done

for each in $(ls *.php | grep nolock | grep -v ^0); do
	touch logs/$each.log
	{
		nice -n 19 php $each >> logs/$each.log 2> >(php ../scratch/errlogger.php)
	} &
done

minute=$(date +%M)
if [ $((10#$minute % 15)) -eq 0 ]; then
	touch locks/update_sde_jsonl.lock
	touch logs/update_sde_jsonl.sh.log
	{
		flock -x -n locks/update_sde_jsonl.lock nice -n 19 ./sde/update_sde_jsonl.sh >> logs/update_sde_jsonl.sh.log 2> >(php ../scratch/errlogger.php)
	} &

	touch locks/update_icons.lock
	touch logs/update_icons.sh.log
	{
		flock -x -n locks/update_icons.lock nice -n 19 ./icons/update_icons.sh >> logs/update_icons.sh.log 2> >(php ../scratch/errlogger.php)
	} &
fi
