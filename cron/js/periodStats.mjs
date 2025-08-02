function updateRanks(period) {
db.statstest.aggregate([
  {
    $match: {
      type: { $ne: "label" },
      [`totals_${period}`]: { $exists: true }
    }
  },
  {
    $setWindowFields: {
      partitionBy: "$type",
      sortBy: { [`totals_${period}.shipsDestroyed`]: -1 },
      output: {
        shipsDestroyedRank: { $rank: {} }
      }
    }
  },
  {
    $setWindowFields: {
      partitionBy: "$type",
      sortBy: { [`totals_${period}.pointsDestroyed`]: -1 },
      output: {
        pointsDestroyedRank: { $rank: {} }
      }
    }
  },
  {
    $setWindowFields: {
      partitionBy: "$type",
      sortBy: { [`totals_${period}.valueDestroyed`]: -1 },
      output: {
        valueDestroyedRank: { $rank: {} }
      }
    }
  },
  {
    $setWindowFields: {
      partitionBy: "$type",
      sortBy: { [`totals_${period}.shipsLost`]: -1 },
      output: {
        shipsLostRank: { $rank: {} }
      }
    }
  },
  {
    $setWindowFields: {
      partitionBy: "$type",
      sortBy: { [`totals_${period}.pointsLost`]: -1 },
      output: {
        pointsLostRank: { $rank: {} }
      }
    }
  },
  {
    $setWindowFields: {
      partitionBy: "$type",
      sortBy: { [`totals_${period}.valueLost`]: -1 },
      output: {
        valueLostRank: { $rank: {} }
      }
    }
  },
  {
    $set: {
      [`ranks_${period}`]: {
        shipsDestroyedRank: "$shipsDestroyedRank",
        pointsDestroyedRank: "$pointsDestroyedRank",
        valueDestroyedRank: "$valueDestroyedRank",
        shipsLostRank: "$shipsLostRank",
        pointsLostRank: "$pointsLostRank",
        valueLostRank: "$valueLostRank"
      }
    }
  },
  {
    $project: {
      _id: 1,
      [`ranks_${period}`]: 1
    }
  },
  {
    $merge: {
      into: "statstest",
      on: "_id",
      whenMatched: "merge",
      whenNotMatched: "discard"
    }
  }
]);

db.statstest.updateMany({[ `totals_${period}` ]: {$exists: false}, [ `ranks_${period}` ]: {$exists: true}}, {$unset: { [ `ranks_${period}` ] : 1 } });

}
function updatePeriodStats(source, target, solo = false) {
  const startEpoch = Date.now();

  if (solo) target = `${target}_solo`;
  db.statstest.updateMany({ [ `totals_${target}` ] : {$exists: true }}, {$set: {touched: false }});

  var ops = [];

  let pipeline = [
  { $unwind: "$involved" },
  {
    $project: {
      killID:"$killID",
      points: "$zkb.points",
      totalValue: "$zkb.totalValue",
      involvedEntities: [
        { type: "characterID",    id: "$involved.characterID",    isVictim: "$involved.isVictim" },
        { type: "corporationID",  id: "$involved.corporationID",  isVictim: "$involved.isVictim" },
        { type: "allianceID",     id: "$involved.allianceID",     isVictim: "$involved.isVictim" },
        { type: "factionID",      id: "$involved.factionID",      isVictim: "$involved.isVictim" },
        { type: "shipTypeID",     id: "$involved.shipTypeID",     isVictim: "$involved.isVictim" },
        { type: "groupID",        id: "$involved.groupID",        isVictim: "$involved.isVictim" }
      ],
      locationEntities: [
        { type: "solarSystemID",   id: "$system.solarSystemID",   isVictim: false },
        { type: "constellationID", id: "$system.constellationID", isVictim: false },
        { type: "regionID",        id: "$system.regionID",        isVictim: false }
      ],
      labelEntities: {
        $map: {
          input: "$labels",
          as: "label",
          in: {
            type: "label",
            id: "$$label",
            isVictim: false
          }
        }
      }
    }
  },
  {
    $project: {
      killID: "$killID",
      points: "$points",
      totalValue: "$totalValue",
      entities: {
        $setUnion: ["$involvedEntities", "$locationEntities", "$labelEntities"]
      }
    }
  },
  { $unwind: "$entities" },
  { $match: { "entities.id": { $ne: null } } },

  {
    $group: {
      _id: {
	      killID: "$killID",
        type: "$entities.type",
        id: "$entities.id",
        isVictim: "$entities.isVictim"
      },
      type: { $first: "$entities.type" },
      id: { $first: "$entities.id" },
      isVictim: { $first: "$entities.isVictim" },
      points: { $first: "$points" },
      totalValue: { $first: "$totalValue" },
    }
  },

  {
    $group: {
      _id: { type: "$type", id: "$id" },
      type: { $first: "$type" },
      id: { $first: "$id" },

      shipsDestroyed: {
        $sum: { $cond: [{ $eq: ["$isVictim", false] }, 1, 0] }
      },
      pointsDestroyed: {
        $sum: { $cond: [{ $eq: ["$isVictim", false] }, "$points", 0] }
      },
      valueDestroyed: {
        $sum: { $cond: [{ $eq: ["$isVictim", false] }, "$totalValue", 0] }
      },

      shipsLost: {
        $sum: { $cond: [{ $eq: ["$isVictim", true] }, 1, 0] }
      },
      pointsLost: {
        $sum: { $cond: [{ $eq: ["$isVictim", true] }, "$points", 0] }
      },
      valueLost: {
        $sum: { $cond: [{ $eq: ["$isVictim", true] }, "$totalValue", 0] }
      }
    }
  },

  {
    $project: {
      _id: 0,
      type: 1,
      id: 1,
      'totals': {
        shipsDestroyed: "$shipsDestroyed",
        pointsDestroyed: "$pointsDestroyed",
        valueDestroyed: "$valueDestroyed",
        shipsLost: "$shipsLost",
        pointsLost: "$pointsLost",
        valueLost: "$valueLost"
      }
    }
  }
];

  if (solo) pipeline.unshift({ $match : {labels: 'solo'}}); // All "solo" killmails are PVP killmails
  else pipeline.unshift({ $match : {labels: 'pvp'}}); // only consider PVP killmails

  db[source].aggregate(pipeline).forEach(e => ops.push({
    updateOne: {
      filter: { type: e.type, id: e.id },
      update: {
         $set:  { [ `totals_${target}` ]: [ e.totals ], touched: true },
      }
    }
  }));

  ops.push( {
    updateMany: {
      filter: { touched: false },
      update: {
        $unset: {
          touched: 1,
          [ `totals_${target}` ]: 1      // remove this totals subdoc
        }
      }
    }
  });

  ops.push({updateMany: {
      filter: { touched: { $exists: true } },
      update: { $unset: { touched: 1 } }}
    });

  if (ops.length) db.statstest.bulkWrite(ops);

  console.log(source, target, ops.length, 'writes /', (Date.now() - startEpoch) / 1000, 'seconds');
}

updatePeriodStats('oneWeek', 'weekly');
updateRanks('weekly');
updatePeriodStats('oneWeek', 'weekly', true);
updateRanks('weekly_solo');

let recentKey = 'zkb:recent:' + new Date().toISOString().slice(0, 10).replace(/-/g, '')
if (db.keyvalues.countDocuments({key: recentKey}) == 0) {
    updatePeriodStats('ninetyDays', 'recent');
    updateRanks('recent');
    updatePeriodStats('ninetyDays', 'recent', true);
    updateRanks('recent_solo');
    db.keyvalues.insertOne({key: recentKey, expiresAt: ((Date.now() / 1000) + 86400)});
}
