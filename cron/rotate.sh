#!/usr/bin/env bash

now=$(date +%H%M)
test "$now" != "0000" && exit # Not midnight? exit now

rotate () {
# Deletes old log file
if [ -f $1 ] ; then
  CNT=2
  let P_CNT=CNT-1
  if [ -f $1.2 ] ; then
    rm $1.2
  fi
   
  # Renames logs .1 trough .4
  while [[ $CNT -ne 1 ]] ; do
    if [ -f $1.${P_CNT} ] ; then
      mv $1.${P_CNT} $1.${CNT}
    fi
    let CNT=CNT-1
    let P_CNT=P_CNT-1
  done
 
  # Renames current log to .1
  mv $1 $1.1
  touch $1
fi
}

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
cd $DIR/logs

for each in *.log; do
  rotate $each
done
