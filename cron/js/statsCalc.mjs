function runPrepAggregation(collection, destination, type) {
  const idField = type;

  db.getCollection(collection).aggregate([
    { $unwind: "$involved" },
    {
      $match: {
        [`involved.${idField}`]: { $ne: null }
      }
    },
    {
      $group: {
        _id: {
          id: `$involved.${idField}`,
          isVictim: "$involved.isVictim"
        },
        totalCount: { $sum: 1 },
        totalValue: { $sum: "$zkb.totalValue" },
        totalPoints: { $sum: "$zkb.points" }
      }
    },
    {
      $group: {
        _id: "$_id.id",
        [destination]: {
          $push: {
            k: { $cond: ["$_id.isVictim", "Lost", "Kills"] },
            v: {
              totalCount: "$totalCount",
              totalValue: "$totalValue",
              totalPoints: "$totalPoints"
            }
          }
        }
      }
    },
    {
      $project: {
        _id: 0,
        id: "$_id",
        type: { $literal: type },
        [destination]: { $arrayToObject: `$${destination}` }
      }
    },
    {
      $merge: {
        into: "statistics",
        on: ["type", "id"],
        whenMatched: "merge",
        whenNotMatched: "insert"
      }
    }
  ]);
}


function moveFieldWithFallback(collection, targetField, sourceField) {
  db[collection].find().forEach(doc => {
    if (doc[sourceField]) {
      db[collection].updateOne(
        { _id: doc._id },
        {
          $set: { [targetField]: doc[sourceField] },
          $unset: { [sourceField]: "" }
        }
      );
    } else {
      db[collection].updateOne(
        { _id: doc._id },
        {
          $unset: { [targetField]: "" }
        }
      );
    }
  });
}

const collections = {'oneWeek': 'week', 'ninetyDays': 'week12'};
const types = ['characterID', 'corporationID', 'allianceID'];

for (const [key, value] of Object.entries(collections)) {
    for (type of types) {
        console.log(key, value, type);
        runPrepAggregation(key, value + "Prep", type);
        moveFieldWithFallback(key, value + "Prep", value);
    }
}

//runPrepAggregation("oneWeek", "weekly", "characterID");
//runPrepAggregation("ninetyDays", "weekly12", "corporationID");
//runPrepAggregation("oneWeek", "weekly", "allianceID");

