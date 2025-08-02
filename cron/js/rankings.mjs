db.collection.aggregate([
  {
    $setWindowFields: {
      sortBy: { totalKills: -1 },
      output: {
        "ranks.weekly.kills": { $rank: {} }
      }
    }
  },
  {
    $setWindowFields: {
      sortBy: { totalValue: -1 },
      output: {
        "ranks.weekly.value": { $rank: {} }
      }
    }
  },
  {
    $setWindowFields: {
      sortBy: { totalPoints: -1 },
      output: {
        "ranks.weekly.points": { $rank: {} }
      }
    }
  },
  {
    $merge: { into: "collection", whenMatched: "merge", whenNotMatched: "discard" }
  }
])

